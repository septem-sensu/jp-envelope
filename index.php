<?php

declare( strict_types = 1 );

/**
 * This file is the entry point of the app: it boots the front controller
 * and prints the result. The try/catch is the safety net for everything
 * before the granular execute() catch (constructor init incl. request
 * scope resolution and app envelope building): instead of a white page
 * the visitor gets the generic emergency page with HTTP 500 — details
 * as everywhere in the hard error channel go into the server log only.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-10
 *
 * @example   index.php?view=viewUserList
 *
 */
include_once ( __DIR__ . '/includes/classes/controller.php' );

try {
  $objController = new Controller();
  echo $objController->execute();
} catch( Throwable $objException ) {
  http_response_code( 500 );
  Controller::logHardError( $objException, 'index.php' );
  echo Controller::HARD_ERROR_HTML;
}

// EOF
