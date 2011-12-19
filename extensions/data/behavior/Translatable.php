<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2011, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_translate\extensions\data\behavior;

use lithium\data\source\MongoDb;
use lithium\util\Set;
use lithium\core\Environment;

/**
 * The `Translateable` class handles all translating MongoDB based content, the data is placed
 * into a language namespace for that record. This also needs to deal with validation to make sure
 * the model acts as expected in all scenarios.
 */
class Translatable extends \lithium\core\StaticObject {

	/**
	 * An array of configurations indexed by model class name, for each model to which this class
	 * is bound.
	 *
	 * @var array
	 */
	protected static $_configurations = array();

	/**
	 * Class dependencies.
	 *
	 * @var array
	 */
	protected static $_classes = array(
		'entity' => 'lithium\data\entity\Document'
	);

	/**
	 * A binding method to grab the class in question, with which you can alter the configuration
	 * and apply filters to
	 *
	 * @var string $class The current model class
	 * @var array $config Configuration options for the behavior
	 * @return 
	 */
	public static function bind($class, array $config = array()) {

		$defaults = array();
		$config += $defaults;
		static::$_configurations = $config;
		
		static::_save($class);
		static::_find($class);
		static::_validates($class);
		
		return static::$_configurations[$class] = $config;
	}

	/**
	 * A protected function to apply our filter to the classes save method.
	 * we add a locale offset to the entity 
	 *
	 * @var class The model class to which the _save filter is applied
	 * @return mixed Upon success the current document will be returned. On fail false.
	 */
	protected static function _save($class){

		$classes = static::$_classes;
		$fields = static::$_configurations['fields'];
		if (isset(static::$_configurations['default'])) {
			$default = static::$_configurations['default'];
		} else {
			$default = Environment::get('locale') ?: null;
		}
		
		if (isset(static::$_configurations['locales'])) {
			$locales = static::$_configurations['locales'];
		} else {
			$locales = array_keys(Environment::get('locales')) ?: null;
		}

		$class::applyFilter('save', 
			function($self, $params, $chain) use ($classes, $fields, $locales, $default) {

			$entity = $params['entity'];
			
			if($params['data']) {
				$entity->set($params['data']);
				$params['data'] = null;
			}

			// Add errors to locale and return if locale has not been set or locale separated
			// content.
			if (!isset($entity->locale)) {
				$localePresent = array_map(
					function($key) use ($entity) { 
						return array_key_exists($key, $entity->data()); 
					}, $locales);
				if (!in_array(true, $localePresent) && !isset($default)) {
					$entity->errors('locale', 'Locale has not been set.');
					return false;
				}
				if (!in_array(true, $localePresent) && isset($default)) {
					$entity->locale = $default;
				}
			}

			$fields[] = 'locale';
			$entityData = $entity->data();

			$processFields = function($fields, $entityData, $locale) use ($entity) {
				$data = array();
				$entityData['locale'] = $locale;

				// Add to data directly from the entity data or from the presaved localization.
				// Data is only added from the translatable fields
				foreach($fields as $key) {

					// If the key is available
					if(isset($entityData[$key])) {
						$data[$key] = $entityData[$key];
					}

					// If the key part of localizations
					if(isset($entityData[$key]) && isset($entityData['localizations'])) {
						foreach($entityData['localizations'] as $key => $localized) {
							if(isset($localized[$key]) && $localized['locale'] == $locale){
								$data[$key] = $localized[$key];
							}
						}
					}
				}
				return $data;
			};

			// Sort out the data from individual locale save mode and multiple
			// exists. If the localization doesn't exist we add the data to the localization array.
			if (isset($entity->locale)) {
				$validation_locale = $entity->locale;
				$data = $processFields($fields, $entityData, $validation_locale);
			}
			else {
				$validation_locale = $default;
				$entityLocalizedSet = array();
				$saveLocalizations = array();
				foreach($locales as $locale){
					if (isset($entityData[$locale])) {
						$saveLocalizations[] = $locale;
						if ($entity->_id) {
							$entityLocalizedSet[$locale] = $processFields($fields, $entityData[$locale], $locale);
						}
						else{
							$entityLocalizedSet[] = $processFields($fields, $entityData[$locale], $locale);
						}
					}
					unset($entity->$locale);
				}
			}

			// Should the record exist we need overwrite the localized data if the localization already
			// exists. If the localization doesn't exist we add the data to the localization array.
			$localizedSet = array();
			$dbLocalizations = array();
			if ($entity->exists() && $record = $self::find(
				(string) $entity->_id, array('Ignore-Locale'=> true)
			)) {
				foreach($record->localizations as $localization) {
					$locale = $localization->locale;
					$dbLocalizations[] = $locale;
					if (!isset($entityLocalizedSet[$locale]) && $locale != $data['locale']) {
						$localizedSet[] = $localization->to('array');
					}
					else {
						if (isset($entityLocalizedSet[$locale])) {
							$data = $entityLocalizedSet[$locale];
						}
						if (isset($data['localizations'])) {
							unset($data['localizations']);
						}
						$data += $localization->to('array');
						$localizedSet[] = $data;
					}
				}
			}

			// If the locale has not been picked up in previously saved localizations
			// regular save fits into this category.
			if (!isset($entityLocalizedSet) && !in_array($validation_locale, $dbLocalizations)) {
				$localizedSet[] = $data;
			}
			
			// If saving multiple translations at once
			if (!$entity->_id && isset($entityLocalizedSet)) {
				$localizedSet = $entityLocalizedSet;
			}

			// If updating multiple translations at once, we need to add the translations
			// that are still not yet covered from the update information
			if ($entity->_id  && isset($entityLocalizedSet)) {
				$toAdd = array_diff($saveLocalizations, $dbLocalizations);
				foreach($toAdd as $locale) {
					$localizedSet[] = $entityLocalizedSet[$locale];
				}
			}

			$entity->localizations = $localizedSet;
			$entity->validation = $validation_locale;
			
			unset($entity->$validation_locale); 
			
			foreach($fields as $key){
				unset($entity->$key);
			}

			$params['entity'] = $entity;

			return $chain->next($self, $params, $chain);
		});
	}

	/**
	 * A protected function to apply our filter to the classes find method.
	 * We grab the document from the documents as needed and pass them to you in language specific
	 * output. If you pass a locale option we return only the document for that locale. If you only
	 * want to search a locale but return all locales then pass locale as a condition.
	 *
	 * @param string $class The current called model class to which the find filter is applied.
	 * @return mixed An integer/document or document set from the current find.
	 */
	protected static function _find($class){
		$fields = static::$_configurations['fields'];
		if (isset(static::$_configurations['locales'])) {
			$locales = static::$_configurations['locales'];
		} else {
			$locales = array_keys(Environment::get('locales')) ?: null;
		}
		$class::applyFilter('find', function($self, $params, $chain) use ($fields, $locales) {
			
			if (isset($params['options']['Ignore-Locale'])) {
				unset($params['options']['Ignore-Locale']);
				return $chain->next($self, $params, $chain);
			}

			$class = __CLASS__;

			if (isset($params['options']['locale'])) {
				$params['options']['conditions']['locale'] = $params['options']['locale'];
			}

			// Need to parse the options find options as needed to keep 
			$options = $class::parseOptions($params['options'], $fields, $locales);
			$params['options'] = $options;
			$result = $chain->next($self, $params, $chain);

			$options += $fields;

			// If this is an integer result send it back as it is.
			if (is_int($result)) {
				return $result;
			}
			
			// Otherwise send it to the result parser which will output it as needed.
			$function = $class::formatReturnDocument($options, $fields);
			if ($params['type'] == 'all' || $params['type'] == 'search') {
				$result->each($function);
				return $result;
			}
			return $function($result);
		});
	}

	/**
	 * A protected method to override model validates.
	 * We take a validation key to get get the record we want to validate, this could be hard in the
	 * case of multi locale saving. But I think we really need to do 1 at a time.
	 * 
	 * @param string $class The current called model class to which the _validates filter is applied.
	 * @return boolean The result of the validation result
	 */
	protected static function _validates($class) {
		$class::applyFilter('validates', function($self, $params, $chain) {
			$origEntity = $params['entity'];
			$entity = clone $params['entity'];
			foreach($entity->localizations as $localization) {

				$isValidationLocale = ($localization->locale == $entity->validation);

				if (isset($entity->validation) && $isValidationLocale && is_object($localization)) {
					foreach($localization->data() as $key => $value) {
						$entity->$key = $value;
					}
					unset($entity->localizations);
				}
				$params['entity'] = $entity;
			}
			$result = $chain->next($self, $params, $chain);
			$errors = $params['entity']->errors();
			if (!empty($origEntity)) {
			 $origEntity->errors($params['entity']->errors());
			}
			return $result;
		});
	}

	/**
	 * Returns a closure that formats the returned document to either include all locales 
	 * or to just to return the single record output.
	 * 
	 * @param array $options Original find options to mainly get the locale needed to return
	 * @param array $fields The fields to which translatability is applies
	 * @return closure Contains logic needed to parse a single result correctly.
	 */
	public static function formatReturnDocument($options, $fields) {

		return function($result) use ($options, $fields) {

			if (!is_object($result) && !isset($result->localizations)) {
				return $result;
			}
			foreach($result->localizations as $localization) {
				$localizationData = $localization->data();
				if(!empty($localizationData)) {
					$locale = $localization->locale;
					$fields[] = 'locale';
				
					if (isset($options['locale']) && $options['locale'] == $locale) {
						foreach($fields as $key){
							$result->$key = $localization->$key;
						}
						return $result;
					}
					$result->$locale = $localization;
				}
			}
			return $result;
		};
	}

	/**
	 * Formats the options to allow for our schema tweaked method of searching data.
	 *
	 * @param array $options Original find options to mainly get the locale needed to return
	 * @param array $fields The fields to which translatability is applies
	 * @return array The parsed options.
	 */
	public static function parseOptions($options, $fields, $locales) {
		$subdocument = 'localizations.';
		$array = array();

		foreach ($options as $option => $values) {
			if (is_array($values) && !empty($values)) {
				foreach ($values as $key => $args) {
					
					// If option has an argument key that starts with a localization
					$hasLocalizedKey = (in_array(true, array_map( function($localization) use ($key) {
						return (strpos($key, $localization . '.') !== false);
					}, $locales)));
					
					if($hasLocalizedKey) {
						list($locale, $optionKey) = explode('.', $key);
						$array[$option][$subdocument . $optionKey] = $args;
						$array[$option][$subdocument . 'locale'] = $locale;
					}
					
					// If the option is part of the localized fields
					$isLocalized = (in_array($key, $fields) || $key == 'locale');
					if ($isLocalized) {
						$array[$option][$subdocument . $key] = $args;
					}
					
					if (!$isLocalized && !$hasLocalizedKey) {
						$array[$option][$key] = $args;
					}
				}
			}
			else {
				$array[$option] = $values;
			}
		}
		return $array;
	}
}

?>