<?php

	require_once(EXTENSIONS . '/multiple_section_event/events/class.event.multiple_section.php');

	Class Extension_Multiple_Section_Event extends Extension {

		private static $provides = array();

		public static function registerProviders() {
			self::$provides = array(
				'events' => array(
					'MultipleSectionEvent' => MultipleSectionEvent::getName()
				)
			);

			return true;
		}

		public static function providerOf($type = null) {
			self::registerProviders();

			if(is_null($type)) return self::$provides;

			if(!isset(self::$provides[$type])) return array();

			return self::$provides[$type];
		}

	}
