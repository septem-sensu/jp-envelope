<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseCollection.php' );
require_once ( __DIR__ . '/../classes/user.php' );

/**
 * This class is the typed collection over User business objects: it
 * declares User as the allowed element type of BaseCollection and
 * inherits the container behavior from it.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.16
 * @since     2026-11-01
 *
 * @example   $objUserCollection = new UserCollection();
 *
 */
class UserCollection extends BaseCollection {

  /**
   * This method declares the allowed element type of the collection.
   *
   * @return    string   The class name of the allowed element type
   *
   */
  public function getAllowedType() : string {
    return User::class;
  }
}

// EOF
