<?php

namespace app\lib;

class TOTP
{
    private static $BASE32_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    private $period = 30;
    private $digest = 'sha1';
    private $digits = 6;
    private $epoch = 0;

    private $secret;
    private $issuer;
    private $label;

    public function __construct(?string $secret)
    {
        if ($secret == null) {
            $secret = $this->generateSecret();
        }
        $this->secret = $secret;
    }

    public static function create(?string $secret = null)
    {
        return new self($secret);
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    public function setIssuer(string $issuer): void
    {
        $this->issuer = $issuer;
    }

    public function verify(string $otp, ?int $timestamp = null, ?int $window = null): bool
    {
        $timestamp = $this->getTimestamp($timestamp);

        if (null === $window) {
            return $this->compareOTP($this->at($timestamp), $otp);
        }

        return $this->verifyOtpWithWindow($otp, $timestamp, $window);
    }

    private function verifyOtpWithWindow(string $otp, int $timestamp, int $window): bool
    {
        $window = abs($window);

        for ($i = 0; $i <= $window; ++$i) {
            $next = $i * $this->period + $timestamp;
            $previous = -$i * $this->period + $timestamp;
            $valid = $this->compareOTP($this->at($next), $otp) ||
                $this->compareOTP($this->at($previous), $otp);

            if ($valid) {
                return true;
            }
        }

        return false;
    }

    public function getProvisioningUri(): string
    {
        $params = [];
        if (30 !== $this->period) {
            $params['period'] = $this->period;
        }
        if (0 !== $this->epoch) {
            $params['epoch'] = $this->epoch;
        }
        $label = $this->getLabel();
        if (null === $label) {
            throw new \InvalidArgumentException('The label is not set.');
        }
        if ($this->hasColon($label)) {
            throw new \InvalidArgumentException('Label must not contain a colon.');
        }
        $params['issuer'] = $this->getIssuer();
        $params['secret'] = $this->getSecret();
        $query = str_replace(['+', '%7E'], ['%20', '~'], http_build_query($params));
        return sprintf('otpauth://totp/%s?%s', rawurlencode((null !== $this->getIssuer() ? $this->getIssuer() . ':' : '') . $label), $query);
    }

    /**
     * The OTP at the specified input.
     */
    private function generateOTP(int $input): string
    {
        $hash = hash_hmac($this->digest, $this->intToByteString($input), $this->base32_decode($this->getSecret()), true);

        $hmac = array_values(unpack('C*', $hash));

        $offset = ($hmac[\count($hmac) - 1] & 0xF);
        $code = ($hmac[$offset + 0] & 0x7F) << 24 | ($hmac[$offset + 1] & 0xFF) << 16 | ($hmac[$offset + 2] & 0xFF) << 8 | ($hmac[$offset + 3] & 0xFF);
        $otp = $code % (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }

    private function at(int $timestamp): string
    {
        return $this->generateOTP($this->timecode($timestamp));
    }

    private function timecode(int $timestamp): int
    {
        return (int) floor(($timestamp - $this->epoch) / $this->period);
    }

    private function getTimestamp(?int $timestamp): int
    {
        $timestamp = $timestamp ?? time();
        if ($timestamp < 0) {
            throw new \InvalidArgumentException('Timestamp must be at least 0.');
        }

        return $timestamp;
    }

    private function generateSecret(): string
    {
        return strtoupper($this->base32_encode(random_bytes(20)));
    }

    private function base32_encode($data)
    {
        $dataSize = strlen($data);
        $res = '';
        $remainder = 0;
        $remainderSize = 0;

        for ($i = 0; $i < $dataSize; $i++) {
            $b = ord($data[$i]);
            $remainder = ($remainder << 8) | $b;
            $remainderSize += 8;
            while ($remainderSize > 4) {
                $remainderSize -= 5;
                $c = $remainder & (31 << $remainderSize);
                $c >>= $remainderSize;
                $res .= self::$BASE32_ALPHABET[$c];
            }
        }
        if ($remainderSize > 0) {
            $remainder <<= (5 - $remainderSize);
            $c = $remainder & 31;
            $res .= self::$BASE32_ALPHABET[$c];
        }

        return $res;
    }

    private function base32_decode($data)
    {
        $data = strtolower($data);
        $dataSize = strlen($data);
        $buf = 0;
        $bufSize = 0;
        $res = '';

        for ($i = 0; $i < $dataSize; $i++) {
            $c = $data[$i];
            $b = strpos(self::$BASE32_ALPHABET, $c);
            if ($b === false) {
                throw new \Exception('Encoded string is invalid, it contains unknown char #'.ord($c));
            }
            $buf = ($buf << 5) | $b;
            $bufSize += 5;
            if ($bufSize > 7) {
                $bufSize -= 8;
                $b = ($buf & (0xff << $bufSize)) >> $bufSize;
                $res .= chr($b);
            }
        }

        return $res;
    }

    private function intToByteString(int $int): string
    {
        $result = [];
        while (0 !== $int) {
            $result[] = \chr($int & 0xFF);
            $int >>= 8;
        }

        return str_pad(implode(array_reverse($result)), 8, "\000", STR_PAD_LEFT);
    }

    private function compareOTP(string $safe, string $user): bool
    {
        return hash_equals($safe, $user);
    }

    private function hasColon(string $value): bool
    {
        $colons = [':', '%3A', '%3a'];
        foreach ($colons as $colon) {
            if (false !== mb_strpos($value, $colon)) {
                return true;
            }
        }

        return false;
    }
}
