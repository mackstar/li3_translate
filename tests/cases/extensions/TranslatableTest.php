<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2011, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_translate\tests\cases\extensions;

use li3_translate\tests\mocks\Artists;

class TranslatableTest extends \lithium\test\Unit {
	
	public function setUp(){
		Artists::remove();
	}

	public function testInitiallyWorking() {
		
		$artist = Artists::create(array(
			'ja.name'=>'Richard Japper', 
			'ja.profile'=>'Dreaded Rasta Nihon', 
			'en.name'=>'Richard', 
			'en.profile'=>'Dreaded Rasta', 'something_else' => 'Something'));
		$this->assertTrue($artist->save());
		
		$artist = Artists::first();
		$this->assertEqual('ja', $artist->validation);
		$artist->save(array('it.name'=>'Ricardo', 'ja.name' => 'リチャード'));
		$artist = Artists::first();
		$this->assertEqual('リチャード', $artist->ja->name);
		$this->assertEqual('Ricardo', $artist->it->name);

		$artist = Artists::all(array('conditions' => array('name' => 'Ricardo'), 'locale' => 'it'));
		$this->assertEqual('Ricardo', $artist->first()->name);

		$artist = Artists::all(array('conditions' => array('it.name' => 'Ricardo')));
		$this->assertEqual('Ricardo', $artist->first()->it->name);

		$artist = Artists::create(array('name'=>'Richard Japper', 'profile'=>'Dreaded Rasta Nihon', 'locale' => 'ja'));
		$this->assertTrue($artist->save());
		$this->assertEqual(2, Artists::count());
		
		$artist = Artists::all(array('conditions' => array('name' => 'Richard Japper'), 'locale' => 'ja'));
		$this->assertEqual('Richard Japper', $artist->first()->name);

		$artist = Artists::first(array('conditions' => array('name' => 'Richard Japper')));
		$this->assertEqual('Richard Japper', $artist->ja->name);

		$artist = Artists::create(array('name'=>'Richard', 'profile'=>'Dreaded Rasta', 'locale' => 'en'));
		$artist->save();

		$artist = Artists::first(array('conditions' => array('name' => 'Richard'), 'locale' => 'en'));
		$this->assertEqual('Richard', $artist->name);
		$artist->name = 'リチャード';
		$artist->locale = 'ja';
		$artist->save();

		$artist = Artists::first(array('conditions' => array('name' => 'リチャード'), 'locale' => 'ja'));
		$this->assertEqual('リチャード', $artist->name);
		$japanese_id = $artist->_id;
		$artist = Artists::first(array('conditions' => array('name' => 'Richard'), 'locale' => 'en'));
		$this->assertEqual($japanese_id , $artist->_id);

		$artist = Artists::all(array('conditions' => array('name' => 'Richard', 'locale' => 'en')));
		$this->assertEqual('Richard', $artist[0]->en->name);
		$this->assertEqual('リチャード', $artist[0]->ja->name);

		$artist = Artists::all(array('conditions' => array('name' => 'Richard'), 'locale' => 'en'));
		$this->assertEqual('Richard', $artist[0]->name);

		$artist = Artists::first(array('locale' => 'en'));
		$this->assertTrue($artist->_id);
		$artist->name = 'Richard Edited';
		$artist->save();

		$this->assertEqual(2, Artists::count(array('conditions' => array('locale' => 'en'))));
		$artist = Artists::first(array('locale' => 'en'));
		$this->assertEqual('Richard Edited', $artist->name);
		
		$artists = Artists::all(array('conditions' => array('name' => 'Richard Japper'), 'locale' => 'en'));
		$this->assertNull($artists->first());
		
	}

	
	
}	