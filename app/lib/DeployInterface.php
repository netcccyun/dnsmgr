<?php

namespace app\lib;

interface DeployInterface
{
    function check();

    function deploy($fullchain, $privatekey, $config, &$info);

    function setLogger($func);
}
