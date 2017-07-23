<?php

namespace SMW;

use SMWDataItem;
use SMWDIBlob as DIBlob;
use SMW\MediaWiki\Jobs\ChangePropagationDispatchJob;

/**
 * Before a new set of data (type, constraints etc.) is stored about a property
 * the class tries to compare old and new specifications (values about that property)
 * and notifies a dispatcher about a change.
 *
 * @license GNU GPL v2+
 * @since 1.9
 *
 * @author mwjames
 * @author Markus Krötzsch
 */
class PropertyChangePropagationNotifier {

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var SerializerFactory
	 */
	private $serializerFactory;

	/**
	 * @var array
	 */
	private $propertyList = array();

	/**
	 * @var boolean
	 */
	private $hasDiff = false;

	/**
	 * @var boolean
	 */
	private $isTypePropagation = false;

	/**
	 * @var boolean
	 */
	private $isCommandLineMode = false;

	/**
	 * @since 1.9
	 *
	 * @param Store $store
	 * @param SerializerFactory $serializerFactory
	 */
	public function __construct( Store $store, SerializerFactory $serializerFactory ) {
		$this->store = $store;
		$this->serializerFactory = $serializerFactory;
	}

	/**
	 * @since 2.5
	 *
	 * @param array $propertyList
	 */
	public function setPropertyList( array $propertyList ) {
		$this->propertyList = $propertyList;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:$wgCommandLineMode
	 * Indicates whether MW is running in command-line mode.
	 *
	 * @since 3.0
	 *
	 * @param boolean $isCommandLineMode
	 */
	public function isCommandLineMode( $isCommandLineMode ) {
		$this->isCommandLineMode = $isCommandLineMode;
	}

	/**
	 * @since 1.9
	 *
	 * @return boolean
	 */
	public function hasDiff() {
		return $this->hasDiff;
	}

	/**
	 * @since 2.5
	 *
	 * @param DIWikiPage $subject
	 */
	public function notify( DIWikiPage $subject ) {

		if ( !$this->hasDiff() || $subject->getNamespace() !== SMW_NS_PROPERTY ) {
			return false;
		}

		$params = array();

		if ( $this->isTypePropagation ) {
			$params['isTypePropagation'] = true;
		}

		return ChangePropagationDispatchJob::planAsJob( $subject, $params );
	}

	/**
	 * Compare and detect differences between the invoked semantic data
	 * and the current stored data
	 *
	 * @note Compare on extra properties from `smwgDeclarationProperties`
	 * (e.g '_PLIST') to find a possible specification change
	 *
	 * @since 1.9
	 */
	public function checkAndNotify( SemanticData &$semanticData ) {

		if ( $semanticData->getSubject()->getNamespace() !== SMW_NS_PROPERTY ) {
			return;
		}

		$this->hasDiff = false;

		// Check the type first
		$propertyList = array_merge(
			array(
				'_TYPE',
				'_CONV',
				'_UNIT',
				'_REDI'
			),
			$this->propertyList
		);

		foreach ( $propertyList as $key ) {

			// No need to keep comparing once a diff has been
			// detected
			if ( $this->hasDiff() ) {
				break;
			}

			$this->doCompare( $semanticData, $key );
		}

		$this->doNotifyAndPostpone( $semanticData );
	}

	private function doCompare( $semanticData, $key ) {

		$property = new DIProperty( $key );

		$newValues = $semanticData->getPropertyValues( $property );

		$oldValues = $this->store->getPropertyValues(
			$semanticData->getSubject(),
			$property
		);

		$this->setDiff( !$this->isEqual( $oldValues, $newValues ), $key );
	}

	private function setDiff( $hasDiff = true, $key ) {

		if ( !$hasDiff || $this->hasDiff ) {
			return;
		}

		$this->hasDiff = true;
		$this->isTypePropagation = $key === '_TYPE';
	}

	/**
	 * Helper function that compares two arrays of data values to check whether
	 * they contain the same content. Returns true if the two arrays contain the
	 * same data values (irrespective of their order), false otherwise.
	 *
	 * @param SMWDataItem[] $oldDataValue
	 * @param SMWDataItem[] $newDataValue
	 *
	 * @return boolean
	 */
	private function isEqual( array $oldDataValue, array $newDataValue ) {

		// The hashes of all values of both arrays are taken, then sorted
		// and finally concatenated, thus creating one long hash out of each
		// of the data value arrays. These are compared.
		$values = array();
		foreach ( $oldDataValue as $v ) {
			$values[] = $v->getHash();
		}

		sort( $values );
		$oldDataValueHash = implode( '___', $values );

		$values = array();
		foreach ( $newDataValue as $v ) {
			$values[] = $v->getHash();
		}

		sort( $values );
		$newDataValueHash = implode( '___', $values );

		return $oldDataValueHash == $newDataValueHash;
	}

	private function doNotifyAndPostpone( SemanticData &$semanticData ) {

		if ( !$this->hasDiff() ) {
			return;
		}

		$this->notify( $semanticData->getSubject() );

		// If executed from the commandLine (cronJob etc.), do not
		// suspend the update
		if ( $this->isCommandLineMode === true ) {
			return;
		}

		$previous = $this->store->getSemanticData(
			$semanticData->getSubject()
		);

		$semanticDataSerializer = $this->serializerFactory->newSemanticDataSerializer();

		$new = $semanticDataSerializer->serialize(
			$semanticData
		);

		// Encode and store the new version of the SemanticData and suspend
		// the update until ChangePropagationDispatchJob was able to select
		// all connected entities
		$previous->addPropertyObjectValue(
			new DIProperty( DIProperty::TYPE_CHANGE_PROP ),
			new DIBlob( json_encode( $new ) )
		);

		$semanticData = $previous;
	}

}