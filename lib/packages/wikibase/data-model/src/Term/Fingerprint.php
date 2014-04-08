<?php

namespace Wikibase\DataModel\Term;

/**
 * @since 0.7.3
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class Fingerprint {

	/**
	 * @return Fingerprint
	 */
	public static function newEmpty() {
		return new self(
			new LabelList( array() ),
			new DescriptionList( array() ),
			new AliasGroupList( array() )
		);
	}

	private $labels;
	private $descriptions;
	private $aliases;

	public function __construct( LabelList $labels, DescriptionList $descriptions, AliasGroupList $aliases ) {
		$this->labels = $labels;
		$this->descriptions = $descriptions;
		$this->aliases = $aliases;
	}

	/**
	 * @return LabelList
	 */
	public function getLabels() {
		return $this->labels;
	}

	/**
	 * @return DescriptionList
	 */
	public function getDescriptions() {
		return $this->descriptions;
	}

	/**
	 * @return AliasGroupList
	 */
	public function getAliases() {
		return $this->aliases;
	}

	/**
	 * @param Label $label
	 */
	public function setLabel( Label $label ) {
		$this->labels = $this->labels->getWithTerm( $label );
	}

}
