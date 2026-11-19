<?php

declare( strict_types = 1 );

/**
 * This class is the fail-loud exception of the persistence validation:
 * saveObject() and insertObject() validate the entry against the field
 * definitions (fields/{klasseKlein}.json) before every write and throw
 * this exception when a rule fails. It carries the field error messages
 * (field name => message) so the generic save route (viewSaveObject) can
 * translate them into its AppError envelopes, while every other caller
 * (actions, hooks, future apis) lets it bubble into the soft error
 * channel of the controller.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.20
 * @since     2026-11-18
 *
 * @example   throw new PersistenceValidationException( [ 'name' => 'Bitte Name angeben.' ], 'DemoOne', 'demoOne_1' );
 *
 */
class PersistenceValidationException extends RuntimeException {

  /**
   * The validation error messages keyed by field name.
   *
   * @var       array
   *
   */
  private array $arrFieldErrors;

  /**
   * This method is the constructor of the exception: it takes the field
   * error messages of the failed validation and assembles the flat
   * exception message from them (class::id plus the joined messages).
   *
   * @param     array     $arrFieldErrors   The validation errors (field name => message)
   * @param     string    $strClass         The business class that failed the validation
   * @param     string    $strId            The object id that failed the validation
   * @return    void
   *
   * @example   throw new PersistenceValidationException( $arrFieldErrors, 'DemoOne', 'demoOne_1' );
   *
   */
  public function __construct( array $arrFieldErrors, string $strClass, string $strId ) {
    parent::__construct( 'Persistenz-Validierung fehlgeschlagen (' . $strClass . '::' . $strId . '): ' . implode( '; ', $arrFieldErrors ) );

    $this->arrFieldErrors = $arrFieldErrors;

    return;
  }

  /**
   * This method returns the validation error messages keyed by field name.
   *
   * @return    array     The validation errors (field name => message)
   *
   * @example   $arrFieldErrors = $objException->fieldErrors();
   *
   */
  public function fieldErrors() : array {
    return $this->arrFieldErrors;
  }

}

// EOF
