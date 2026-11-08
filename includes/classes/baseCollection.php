<?php

declare( strict_types = 1 );

/**
 * This class is the abstract base class of the typed collections: an
 * iterable container (IteratorAggregate, Countable) over instances of
 * one allowed business class, declared per subclass through
 * getAllowedType(). Every add access validates each element against
 * that type and throws an InvalidArgumentException on a mismatch.
 * Collections bracket the persistence contract of BaseObject: a typed
 * collection property of a business object is filled from the stored
 * id list via fillObject() and reported as sub envelopes with the
 * contained ids via rawObject().
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.16
 * @since     2026-11-01
 *
 * @example   $objUserCollection = new UserCollection();
 * @example   $objUserCollection->push( new User( 'someUserId' ) );
 *
 */
abstract class BaseCollection implements IteratorAggregate, Countable {

  /**
   * The items of the collection (instances of the allowed type).
   *
   * @var       array
   *
   */
  protected array $items = [];

  /**
   * This abstract method declares the allowed element type of the
   * collection. Subclasses return their business class name (e.g.
   * UserCollection returns User::class); every add access of
   * BaseCollection checks each element against it.
   *
   * @return    string   The class name of the allowed element type
   *
   */
  abstract public function getAllowedType() : string;

  /**
   * This method appends one element or multiple elements at the end of
   * the collection. Non-array input is taken as a single element, array
   * input is appended element by element in the given order. Every
   * element must be an instance of getAllowedType(), otherwise an
   * InvalidArgumentException is thrown; elements appended before the
   * mismatch stay in place.
   *
   * @param     mixed   $mixItems   An element instance of the allowed type or an array of them
   * @return    void
   *
   * @example   $objUserCollection->push( $objUser );
   *
   */
  public function push( mixed $mixItems, bool $boolOnlyUnique = true ) : void {
    $strAllowedType = $this->getAllowedType();
    $strItemsType   = gettype( $mixItems );

    if( $strItemsType !== 'array' ) {
      if( ! ( $mixItems instanceof $strAllowedType ) ) throw new InvalidArgumentException( sprintf( "Element muss vom Typ %s sein, %s gegeben.", $strAllowedType, gettype( $mixItems ) ) );
      if( $boolOnlyUnique && in_array( $mixItems->id(), $this->items ) ) return;

      $this->items[] = $mixItems;

      return;
    }

    foreach( $mixItems as $mixItem ) {
      if( ! ( $mixItem instanceof $strAllowedType ) ) throw new InvalidArgumentException( sprintf( "Element muss vom Typ %s sein, %s gegeben.", $strAllowedType, gettype( $mixItem ) ) );
      if( $boolOnlyUnique && in_array( $mixItem->id(), $this->items ) ) continue;

      array_push( $this->items, $mixItem );
    }

    return;
  }

  /**
   * This method prepends one element or multiple elements at the
   * front of the collection. Non-array input is taken as a single
   * element, array input is prepended as a whole, preserving its
   * order (the iteration over the reversed array). Every element
   * must be an instance of getAllowedType(), otherwise an
   * InvalidArgumentException is thrown; elements prepended before
   * the mismatch stay in place.
   *
   * @param     mixed   $mixItems   An element instance of the allowed type or an array of them
   * @return    void
   *
   * @example   $objUserCollection->unshift( $objUser );
   *
   */
  public function unshift( mixed $mixItems, bool $boolOnlyUnique = true ) : void {
    $strAllowedType = $this->getAllowedType();
    $strItemsType   = gettype( $mixItems );

    if( $strItemsType !== 'array' ) {
      if( ! ( $mixItems instanceof $strAllowedType ) ) throw new InvalidArgumentException( sprintf( "Element muss vom Typ %s sein, %s gegeben.", $strAllowedType, gettype( $mixItems ) ) );
      if( $boolOnlyUnique && in_array( $mixItems->id(), $this->items ) ) return;

      array_unshift( $this->items, $mixItems );

      return;
    }

    foreach( array_reverse( $mixItems ) as $mixItem ) {
      if( ! ( $mixItem instanceof $strAllowedType ) ) throw new InvalidArgumentException( sprintf( "Element muss vom Typ %s sein, %s gegeben.", $strAllowedType, gettype( $mixItem ) ) );
      if( $boolOnlyUnique && in_array( $mixItem->id(), $this->items ) ) continue;

      array_unshift( $this->items, $mixItem );
    }

    return;
  }

  /**
   * This method removes the last element of the collection and
   * returns it (null when the collection is empty).
   *
   * @return    mixed   The removed element or null
   *
   * @example   $objUser = $objUserCollection->pop();
   *
   */
  public function pop() : mixed {
    return array_pop( $this->items );
  }

  /**
   * This method removes the first element of the collection and
   * returns it (null when the collection is empty).
   *
   * @return    mixed   The removed element or null
   *
   * @example   $objUser = $objUserCollection->shift();
   *
   */
  public function shift() : mixed {
    return array_shift( $this->items );
  }

  /**
   * This method removes all elements of the collection.
   *
   * @return    void
   *
   * @example   $objUserCollection->clear();
   *
   */
  public function clear() : void {
    $this->items = [];

    return;
  }

  /**
   * This method returns the elements of the collection as an array,
   * in their insertion order. For the fillObject()/rawObject() pair
   * it is the single read route onto the container content.
   *
   * @return    array   The elements of the collection as an array
   *
   * @example   $arrUsers = $objUserCollection->getAll();
   *
   */
  public function getAll() : array {
    return $this->items;
  }

  /**
   * This method returns the ids of the collection elements as an
   * array, in their insertion order. It is the id-list read route
   * of the persistence contract: serializeObjectForSave() stores
   * collection properties as this list and rawObject() reports it
   * as the value of the collection descriptor.
   *
   * @return    array   The ids of the collection elements as an array
   *
   * @example   $arrUserIds = $objUserCollection->idArray();
   *
   */
  public function idArray() : array {
    return array_values( array_map( fn( BaseObject $objItem ) : string => $objItem->id(), $this->items ) );
  }

  /**
   * This method replaces the complete collection content: it clears
   * the current elements and appends the given ones via push()
   * (with the same type validation, single element or array).
   *
   * @param     mixed   $mixItems   An element instance of the allowed type or an array of them
   * @return    void
   *
   * @example   $objUserCollection->set( $arrUsers );
   *
   */
  public function set( mixed $mixItems ) : void {
    $this->clear();
    $this->push( $mixItems );

    return;
  }

  /**
   * This method is the IteratorAggregate implementation: it returns
   * an iterator over the collection elements, so that every
   * collection instance is usable in foreach loops.
   *
   * @return    Traversable   The iterator over the collection elements
   *
   * @example   foreach( $objUserCollection as $objUser ) { ... }
   *
   */
  public function getIterator() : Traversable {
    return new ArrayIterator( $this->items );
  }

  /**
   * This method is the Countable implementation: it returns the
   * number of elements of the collection, so that a collection
   * instance is usable with the count() function.
   *
   * @return    int   The number of collection elements
   *
   * @example   $intCount = count( $objUserCollection );
   *
   */
  public function count() : int {
    return count( $this->items );
  }
}

// EOF
