<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );
require_once ( __DIR__ . '/../classes/demoTwo.php' );

class DemoOne extends BaseObject {

  protected DemoTwo $demoTwo;
  protected string $name;
  protected string $lastName;

}

// EOF
