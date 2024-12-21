<?php

namespace app\lib\acme;

use Exception;

class ACME_Exception extends Exception
{
	private $type, $subproblems;
	function __construct($type, $detail, $subproblems = array())
	{
		$this->type = $type;
		$this->subproblems = $subproblems;
		parent::__construct($detail);
	}
	function getType()
	{
		return $this->type;
	}
	function getSubproblems()
	{
		return $this->subproblems;
	}
}
