<?php

namespace li3_translate\tests\mocks;

use li3_behaviors\extensions\model\Behaviors;

class Artists extends \li3_behaviors\extensions\Model {
	
	public $validates = array(
		'name' => array(
			array('notEmpty', 'message' => 'Username should not be empty.'),
			array('lengthBetween', 'min' => 4, 'max' => 20, 'message' => 'Username should be between 5 and 20 characters.')
		),
	);

	public $_actsAs = array(
		'Translatable' => array(
			'default' => 'ja',
			'locales' => array('en', 'it', 'ja'),
			'fields' => array('name', 'profile')
		)
	);

}
?>