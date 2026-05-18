<?php

namespace app\controller;

use app\BaseController;
use app\lib\oauth\OAuthUserInfo;
use app\service\oauth\OAuthAuthService;
use Exception;
use think\facade\Db;

class OauthController extends BaseController
{
    public function login($id)
    {
        if ($this->request->islogin) {
            return redirect('/');
        }

        try {
            return redirect((new OAuthAuthService())->buildLoginRedirect((int)$id, $this->getCallbackUrl($id)));
        } catch (Exception $e) {
            trace($this->buildOAuthErrorLog('init', (int)$id, null, $e), 'error');
            return $this->alert('error', '无法跳转到第三方登录，请稍后重试或联系管理员检查登录配置');
        }
    }

    public function bind($id)
    {
        if (!checkPermission(1)) {
            return $this->alert('error', '无权限');
        }
        if (!checkRefererHost()) {
            return $this->alert('error', '非法请求');
        }

        try {
            return redirect((new OAuthAuthService())->buildBindRedirect((int)$id, (int)$this->request->user['id'], $this->getCallbackUrl($id)));
        } catch (Exception $e) {
            trace($this->buildOAuthErrorLog('bind', (int)$id, (int)$this->request->user['id'], $e), 'error');
            return $this->alert('error', '无法跳转到第三方绑定授权页，请稍后重试或联系管理员检查登录配置');
        }
    }

    public function callback($id)
    {
        $code = input('get.code', null, 'trim');
        $state = input('get.state', null, 'trim');

        if (empty($code) || empty($state)) {
            return $this->alert('error', '授权失败，请重新登录');
        }

        try {
            $currentUserId = $this->request->islogin ? (int)$this->request->user['id'] : null;
            $service = new OAuthAuthService();
            $result = $service->handleCallback((int)$id, $code, $state, $this->getCallbackUrl($id), $currentUserId);
            if ($result['type'] === 'alert') {
                return $this->alert($result['level'], $result['msg'], $result['url'] ?? null);
            }
            if (!empty($result['requiresTotp'])) {
                session('pre_login_user', $result['user']['id']);
                session('oauth_totp_pending', [
                    'user_id' => (int)$result['user']['id'],
                    'provider' => $result['provider'],
                    'userInfo' => $result['userInfo'],
                    'tokenData' => $result['tokenData'],
                ]);
                session('totp_attempt', null);
                return redirect('/login?totp=1');
            }
            try {
                $service->updateLoginBinding($result['provider'], $result['userInfo'], $result['tokenData']);
            } catch (Exception $e) {
                trace($this->buildOAuthErrorLog('refresh_binding', (int)$id, (int)$result['user']['id'], $e), 'error');
            }
            $this->regenerateSessionIdIfActive();
            $this->loginUser($result['user'], $result['provider'], $result['userInfo']);
            return redirect('/');
        } catch (Exception $e) {
            trace($this->buildOAuthErrorLog('callback', (int)$id, $this->request->islogin ? (int)$this->request->user['id'] : null, $e), 'error');
            return $this->alert('error', $this->buildUserFacingOauthMessage($e));
        }
    }

    public function unbind()
    {
        if (!checkPermission(1)) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!checkRefererHost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }

        $providerId = input('post.provider_id/d');
        if (empty($providerId)) {
            return json(['code' => -1, 'msg' => '参数错误']);
        }

        try {
            (new OAuthAuthService())->unbind((int)$this->request->user['id'], (int)$providerId);
            return json(['code' => 0, 'msg' => '解绑成功']);
        } catch (Exception $e) {
            trace($this->buildOAuthErrorLog('unbind', (int)$providerId, (int)$this->request->user['id'], $e), 'error');
            return json(['code' => -1, 'msg' => $this->buildUserFacingOauthMessage($e)]);
        }
    }

    private function regenerateSessionIdIfActive(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function loginUser(array $user, array $provider, OAuthUserInfo $userInfo): void
    {
        Db::name('log')->insert([
            'uid' => $user['id'],
            'action' => 'OAuth登录后台',
            'data' => 'Provider:' . $provider['name'] . ', IP:' . $this->clientip,
            'addtime' => date('Y-m-d H:i:s'),
        ]);
        Db::name('user')->where('id', $user['id'])->update(['lasttime' => date('Y-m-d H:i:s')]);

        $session = md5($user['id'] . $user['password']);
        $expiretime = time() + 2562000;
        $token = authcode("user\t{$user['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true, 'samesite' => 'Lax', 'secure' => request()->isSsl()]);
    }

    private function buildUserFacingOauthMessage(Exception $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return '第三方登录失败：服务返回了空错误信息，请重新发起登录';
        }
        $message = $this->redactOAuthMessage($message);
        return '第三方登录失败：' . $message;
    }

    private function buildOAuthErrorLog(string $flow, int $providerId, ?int $userId, Exception $e): string
    {
        return 'OAuth ' . $flow . ' error: provider_id=' . $providerId . ', user_id=' . ($userId ?? 0) . ', message=' . $this->redactOAuthMessage($e->getMessage());
    }

    private function redactOAuthMessage(string $message): string
    {
        $keys = 'access_token|refresh_token|client_secret|appkey|appid|id_token|code';
        $message = preg_replace('/(' . $keys . ')=([^&\s]+)/i', '$1=[redacted]', $message);
        $message = preg_replace('/("(?:' . $keys . ')"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $message);
        return preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $message);
    }

    private function getCallbackUrl($id): string
    {
        $baseUrl = config('app.app_host') ?: request()->root(true);
        return rtrim($baseUrl, '/') . '/oauth/callback/' . $id;
    }
}
