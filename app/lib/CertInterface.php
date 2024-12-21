<?php

namespace app\lib;

interface CertInterface
{
    function register();

    function buyCert($domainList, &$order);

    function createOrder($domainList, &$order, $keytype, $keysize);

    function authOrder($domainList, $order);

    function getAuthStatus($domainList, $order);

    function finalizeOrder($domainList, $order, $keytype, $keysize);

    function revoke($order, $pem);

    function cancel($order);

    function setLogger($func);
}
