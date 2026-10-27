<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );

/**
 * This class represents a soft error for the storefront.
 * It is NOT persisted (ephemeral per request): the controller collects
 * instances via addError() and throws them into the rawObjects collector
 * after the actions as envelopes (id pattern 'error{N}'). The frontend
 * shows every instance centrally in app.js. Deliberately not named Error:
 * that would collide with the PHP built-in (Throwable base) and
 * window.Error in JS — the JS class file is named appError.js for the
 * same reason.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-20
 *
 * @example   $objError = new AppError( 'error1' );
 *
 */
class AppError extends BaseObject {

  /**
   * The error message for the frontend display.
   *
   * @var       string
   *
   */
  protected string        $message;

  /**
   * The severity of the error: 'error' or 'warning'.
   *
   * @var       string
   *
   */
  protected string        $severity;

  /**
   * The action trigger that rejected (e.g. 'changeUser').
   *
   * @var       string|null
   *
   */
  protected string | null $source      = null;

  /**
   * This method returns the error message for the frontend display.
   *
   * @return    string   The error message
   *
   * @example   $strMessage = $objError->message();
   *
   */
  public function message() : string {
    return $this->message;
  }

  /**
   * This method returns the severity of the error ('error' or 'warning').
   *
   * @return    string   The severity
   *
   * @example   $strSeverity = $objError->severity();
   *
   */
  public function severity() : string {
    return $this->severity;
  }

  /**
   * This method returns the action trigger that rejected or null.
   *
   * @return    string|null   The source or null when not set
   *
   * @example   $strSource = $objError->source();
   *
   */
  public function source() : string | null {
    if( ! isset( $this->source ) ) return null;

    return $this->source;
  }

  /**
   * This method sets the error attributes: the message for the frontend,
   * the action trigger that rejected and the severity ('error' default
   * or 'warning').
   *
   * @param     string        $strMessage    The error message for the frontend
   * @param     string|null   $strSource     The action trigger (e.g. 'changeUser') or null
   * @param     string        $strSeverity   The severity, 'error' (default) or 'warning'
   * @return    void
   *
   * @example   $objError->setMessage( 'Ungültige Eingabe.', 'createUser', 'error' );
   *
   */
  public function setMessage( string $strMessage, string | null $strSource = null, string $strSeverity = 'error' ) : void {
    $this->message  = $strMessage;
    $this->severity = $strSeverity;
    $this->source   = $strSource;

    return;
  }

}

// EOF
