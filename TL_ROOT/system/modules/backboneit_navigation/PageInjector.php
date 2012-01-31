<?php

class PageInjector extends AbstractNavigationInjector {
	
	protected function calculateInjectionPoints() {
		foreach(deserialize($this->arrConfig['pages'], true) as $intID) {
			$this->arrInjectionPoints[] = array('id' => $intID, 'where' => $this->arrConfig['injection']);
		}
	}
	
}