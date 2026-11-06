<?php

declare( strict_types = 1 );

/**
 * This suite is the regression test for the delete propagation of
 * BaseObject::deleteObject(): the reference properties of loaded
 * business classes that reference the deleted object are cleaned
 * before the own entry is removed (phase A cleans other classes under
 * their own file lock, phase B cleans self-references inline within
 * the own lock). The suite covers the class reference (unset), the
 * collection reference (filtered id list keeping the order), the
 * reference switch regression (a target that is no longer referenced
 * stays untouched when deleted), the self-reference of a class onto
 * itself, the rejection of missing ids and the idempotence of a
 * second delete. The suite writes only into its own class json files
 * derived from the test class names (deleteTestTarget.json,
 * deleteTestSource.json, deleteTestNode.json) — they are removed on
 * every suite end by the shutdown handler, the local production data
 * files are never touched.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.17
 * @since     2026-11-06
 *
 * @example   php includes/tests/deletePropagationTest.php
 *
 */

require_once ( __DIR__ . '/bootstrap.php' );

$strTargetsFile = BaseObject::FILEPATHJSON . 'data/deleteTestTarget.json';
$strSourcesFile = BaseObject::FILEPATHJSON . 'data/deleteTestSource.json';
$strNodesFile   = BaseObject::FILEPATHJSON . 'data/deleteTestNode.json';

/**
 * This shutdown handler removes the three class json files of the test
 * classes including their lock side files — it runs on every suite
 * end, including a FEHLER exit of check().
 *
 * @return    void
 *
 */
register_shutdown_function( function() use ( $strTargetsFile, $strSourcesFile, $strNodesFile ) : void {
  @unlink( $strTargetsFile );
  @unlink( $strTargetsFile . '.lock' );
  @unlink( $strSourcesFile );
  @unlink( $strSourcesFile . '.lock' );
  @unlink( $strNodesFile );
  @unlink( $strNodesFile . '.lock' );

  return;
} );

/**
 * Local test target: a minimal BaseObject subclass with one payload
 * property — the referenced class of the delete test source.
 *
 */
class DeleteTestTarget extends BaseObject {

  /**
   * The payload property of the test target.
   *
   * @var       string
   *
   */
  protected string $strName = '';

  /**
   * This method sets the payload property of the test target.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setName( string $strValue ) : void {
    $this->strName = $strValue;

    return;
  }

}

/**
 * Local test collection: declares DeleteTestTarget as the allowed
 * element type.
 *
 */
class DeleteTestTargetCollection extends BaseCollection {

  /**
   * This method declares the allowed element type of the collection.
   *
   * @return    string   The class name of the allowed element type
   *
   */
  public function getAllowedType() : string {
    return DeleteTestTarget::class;
  }

}

/**
 * Local test source: a BaseObject with a 'class' reference and a
 * 'collection' reference onto DeleteTestTarget — the object whose
 * entries the delete propagation has to clean.
 *
 */
class DeleteTestSource extends BaseObject {

  /**
   * The builtin payload property of the test source.
   *
   * @var       string
   *
   */
  protected string $strPayload = '';

  /**
   * The 'class' reference property of the test source.
   *
   * @var       DeleteTestTarget|null
   *
   */
  protected ?DeleteTestTarget $objTarget = null;

  /**
   * The 'collection' reference property of the test source.
   *
   * @var       DeleteTestTargetCollection|null
   *
   */
  protected ?DeleteTestTargetCollection $objTargets = null;

  /**
   * This method sets the builtin payload property of the test source.
   *
   * @param     string    $strValue   The payload value
   * @return    void
   *
   */
  public function setPayload( string $strValue ) : void {
    $this->strPayload = $strValue;

    return;
  }

  /**
   * This method sets the 'class' reference property of the test source.
   *
   * @param     DeleteTestTarget   $objValue   The target to reference
   * @return    void
   *
   */
  public function setTarget( DeleteTestTarget $objValue ) : void {
    $this->objTarget = $objValue;

    return;
  }

  /**
   * This method sets the 'collection' reference property of the test
   * source.
   *
   * @param     array   $arrTargets   The target instances to reference
   * @return    void
   *
   */
  public function setTargets( array $arrTargets ) : void {
    $this->objTargets = new DeleteTestTargetCollection();
    $this->objTargets->push( $arrTargets );

    return;
  }

}

/**
 * Local test node: a self-referencing BaseObject (collection onto the
 * own class) — the phase B case of the delete propagation.
 *
 */
class DeleteTestNode extends BaseObject {

  /**
   * The self-reference collection property of the test node.
   *
   * @var       DeleteTestNodeCollection|null
   *
   */
  protected ?DeleteTestNodeCollection $objNodes = null;

  /**
   * This method sets the self-reference collection property of the
   * test node.
   *
   * @param     array   $arrNodes   The node instances to reference
   * @return    void
   *
   */
  public function setNodes( array $arrNodes ) : void {
    $this->objNodes = new DeleteTestNodeCollection();
    $this->objNodes->push( $arrNodes );

    return;
  }

}

/**
 * Local test node collection: declares DeleteTestNode as the allowed
 * element type.
 *
 */
class DeleteTestNodeCollection extends BaseCollection {

  /**
   * This method declares the allowed element type of the collection.
   *
   * @return    string   The class name of the allowed element type
   *
   */
  public function getAllowedType() : string {
    return DeleteTestNode::class;
  }

}

/* Cleanup of a previous suite run (also removed by the shutdown handler). */

@unlink( $strTargetsFile );
@unlink( $strTargetsFile . '.lock' );
@unlink( $strSourcesFile );
@unlink( $strSourcesFile . '.lock' );
@unlink( $strNodesFile );
@unlink( $strNodesFile . '.lock' );

/* Setup: three targets, two sources with reference properties. */

$objTarget = new DeleteTestTarget( 't1' );
$objTarget->setName( 'Eins' );
BaseObject::insertObject( 'DeleteTestTarget', 't1', $objTarget->serializeObjectForSave() );

$objTarget = new DeleteTestTarget( 't2' );
$objTarget->setName( 'Zwei' );
BaseObject::insertObject( 'DeleteTestTarget', 't2', $objTarget->serializeObjectForSave() );

$objTarget = new DeleteTestTarget( 't3' );
$objTarget->setName( 'Drei' );
BaseObject::insertObject( 'DeleteTestTarget', 't3', $objTarget->serializeObjectForSave() );

$objSource = new DeleteTestSource( 's1' );
$objSource->setPayload( 'alpha' );
$objSource->setTarget( new DeleteTestTarget( 't1' ) );
$objSource->setTargets( [ new DeleteTestTarget( 't1' ), new DeleteTestTarget( 't2' ), new DeleteTestTarget( 't3' ) ] );
BaseObject::insertObject( 'DeleteTestSource', 's1', $objSource->serializeObjectForSave() );

$objSource = new DeleteTestSource( 's2' );
$objSource->setPayload( 'beta' );
$objSource->setTarget( new DeleteTestTarget( 't2' ) );
BaseObject::insertObject( 'DeleteTestSource', 's2', $objSource->serializeObjectForSave() );

/* Scenario 1: deleting a target cleans the class reference (unset)
   and the collection reference (filtered id list, order kept) of the
   sources, untouched properties survive. */

$objDeleted = new DeleteTestTarget( 't1' );
check( $objDeleted->deleteObject() === true, 'deleteObject deletes the existing target t1' );

$objRawSources = BaseObject::getRawObjectsFromClass( 'DeleteTestSource' );
check( ! isset( $objRawSources->s1->objTarget ), 'delete propagation unsets the class reference of s1' );
check( $objRawSources->s1->objTargets === [ 't2', 't3' ], 'delete propagation filters the collection reference of s1 keeping the order' );
check( $objRawSources->s1->strPayload === 'alpha', 'delete propagation leaves untouched properties of s1 intact' );
check( $objRawSources->s2->objTarget === 't2', 'delete propagation leaves non matching references of s2 intact' );

/* Scenario 2: reference switch regression — s3 points to t2, switches
   to t3, deleting the old target t2 must leave the switched reference
   intact (the scan reads the single source of truth, there is no index
   that can drift). */

$objSource = new DeleteTestSource( 's3' );
$objSource->setTarget( new DeleteTestTarget( 't2' ) );
BaseObject::insertObject( 'DeleteTestSource', 's3', $objSource->serializeObjectForSave() );

$objSwitch = new DeleteTestSource( 's3' );
$objSwitch->setTarget( new DeleteTestTarget( 't3' ) );
$objSwitch->saveObject();

$objDeleted = new DeleteTestTarget( 't2' );
check( $objDeleted->deleteObject() === true, 'deleteObject deletes the existing target t2' );

$objRawSources = BaseObject::getRawObjectsFromClass( 'DeleteTestSource' );
check( $objRawSources->s3->objTarget === 't3', 'deleting the old target does not touch the switched reference of s3' );
check( $objRawSources->s1->objTargets === [ 't3' ], 'deleting t2 also cleans the remaining collection reference of s1' );
check( ! isset( $objRawSources->s2->objTarget ), 'the reference of s2 onto the deleted t2 is cleaned' );

/* Scenario 3: self-reference (phase B) — deleting a node cleans the
   self-reference collections of the other nodes of the same class
   inline within the own lock. */

$objNode = new DeleteTestNode( 'n1' );
$objNode->setNodes( [ new DeleteTestNode( 'n2' ), new DeleteTestNode( 'n3' ) ] );
BaseObject::insertObject( 'DeleteTestNode', 'n1', $objNode->serializeObjectForSave() );

$objNode = new DeleteTestNode( 'n2' );
$objNode->setNodes( [ new DeleteTestNode( 'n3' ) ] );
BaseObject::insertObject( 'DeleteTestNode', 'n2', $objNode->serializeObjectForSave() );

$objNode = new DeleteTestNode( 'n3' );
BaseObject::insertObject( 'DeleteTestNode', 'n3', $objNode->serializeObjectForSave() );

$objDeleted = new DeleteTestNode( 'n3' );
check( $objDeleted->deleteObject() === true, 'deleteObject deletes the existing node n3' );

$objRawNodes = BaseObject::getRawObjectsFromClass( 'DeleteTestNode' );
check( $objRawNodes->n1->objNodes === [ 'n2' ], 'delete propagation cleans the self-reference of n1 (phase B)' );
check( $objRawNodes->n2->objNodes === [], 'delete propagation keeps the emptied self-reference of n2 as an empty list' );
check( ! isset( $objRawNodes->n3 ), 'the deleted node itself is removed from the file' );

/* Scenario 4: a missing id is rejected without touching the files. */

$objRawBefore = BaseObject::loadFileDeCrypted( $strTargetsFile );

$objDeleted = new DeleteTestTarget( 'missing' );
check( $objDeleted->deleteObject() === false, 'deleteObject rejects a missing id' );

$objRawAfter = BaseObject::loadFileDeCrypted( $strTargetsFile );
check( $objRawBefore == $objRawAfter, 'the rejected delete leaves the target file untouched' );

/* Scenario 5: a second delete of the same id is rejected, the
   references onto the deleted target are cleaned once. */

$objDeleted = new DeleteTestTarget( 't3' );
check( $objDeleted->deleteObject() === true, 'deleteObject deletes the existing target t3' );
check( ( new DeleteTestTarget( 't3' ) )->deleteObject() === false, 'a second delete of the same id is rejected' );

$objRawSources = BaseObject::getRawObjectsFromClass( 'DeleteTestSource' );
check( $objRawSources->s1->objTargets === [], 'the emptied collection reference of s1 persists as an empty list' );
check( ! isset( $objRawSources->s3->objTarget ), 'deleting the live target t3 unsets the reference of s3' );

// EOF
