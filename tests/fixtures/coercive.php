<?php

// Intentionally without strict_types: dibi 5 coerces scalar arguments passed from here.

namespace Tests\Fixtures;

use Dibi\DateTime;


function newDateTime($time)
{
	return new DateTime($time);
}


function setTimestamp(DateTime $date, $timestamp)
{
	return $date->setTimestamp($timestamp);
}


class UntypedSubDateTime extends DateTime
{
	public function format($format)
	{
		return parent::format($format);
	}


	public function getTimestamp()
	{
		return parent::getTimestamp();
	}


	public function diff($targetObject, $absolute = false)
	{
		return parent::diff($targetObject, $absolute);
	}
}


class Stringable
{
	public function __toString(): string
	{
		return '2020-01-01 10:00:00';
	}
}


class CloningSubDateTime extends DateTime
{
	public function __clone()
	{
	}
}
