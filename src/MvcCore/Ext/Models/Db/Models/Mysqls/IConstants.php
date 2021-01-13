<?php

/**
 * MvcCore
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flídr (https://github.com/mvccore/mvccore)
 * @license  https://mvccore.github.io/docs/mvccore/5.0.0/LICENCE.md
 */

namespace MvcCore\Ext\Models\Db\Models\MySqls;

interface IConstants {

	/** @var int */
	const TRANS_CONSISTENT_SHAPSHOT	= 16;

	/** @var int */
	const TRANS_READ_WRITE			= 32;
	
	/** @var int */
	const TRANS_READ_ONLY			= 64;
	
	/** @var int */
	const TRANS_CHAIN				= 128;
	
	/** @var int */
	const TRANS_NO_CHAIN			= 256;
}