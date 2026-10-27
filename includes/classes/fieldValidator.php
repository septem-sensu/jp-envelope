<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );
require_once ( __DIR__ . '/../classes/presentation.php' );
require_once ( __DIR__ . '/../classes/request.php' );

/**
 * This class is the server side counterpart of the frontend fieldValidator.js:
 * it validates request values against the form field definitions of a
 * business class (includes/json/fields/{klasseKlein}.json) — the same
 * single source of truth the frontend uses. Actions call validate() with
 * the class name and an optional fieldset: without a fieldset every
 * declared field is validated with its own mandatory flag (the create
 * case), with a fieldset only the listed fields run — bare entries stay
 * mandatory, boolean entries downgrade the field to optional. The method
 * reports instead of aborting: it returns the first error message per
 * field (field name => message), so actions can collect soft errors via
 * addError() and keep their partial save semantics. Domain guards
 * (existence, permissions, ownership) deliberately stay in the actions.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.12
 * @since     2026-10-25
 *
 * @example   $arrFieldErrors = FieldValidator::validate( 'User' );
 *            $arrFieldErrors = FieldValidator::validate( 'User', [ 'id', 'password' => false ] );
 *
 */
class FieldValidator {

  /**
   * The error message templates keyed by rule name. Placeholders in curly
   * braces are replaced in message(): {field} by the nameDe attribute of
   * the field definition (fallback: the field name), rule parameters like
   * {minLength} by their value. Per field and rule a template can be
   * overridden by a messages entry in the field definition.
   *
   * @var       array
   *
   */
  const MESSAGES = [
    "mandatory"        => "Bitte {field} angeben.",
    "minLength"        => "{field} muss mindestens {minLength} Zeichen haben.",
    "passwordRepeat"   => "Bitte {field} und {field}-Wiederholung angeben.",
    "passwordMatch"    => "{field} und {field}-Wiederholung stimmen nicht überein.",
    "passwordSecurity" => "Das {field} entspricht nicht den Passwort-Regeln.",
    "select"           => "Die ausgewählte {field} ist ungültig.",
    "mail"             => "{field} ist keine gültige E-Mail-Adresse."
  ];

  /**
   * This method validates the request values of a class against its form
   * field definitions. Without a fieldset every declared field runs with
   * its own mandatory attribute; with a fieldset only the listed fields
   * run — a bare entry (numeric key) stays mandatory, an entry with a
   * string key takes the boolean value as its mandatory flag. Fields
   * without a definition are skipped silently. It returns the first error
   * message per failed field (field name => message), an empty array
   * means every value is valid.
   *
   * @param     string         $strClassName   The business class to validate for
   * @param     array|null     $arrFieldset    The fieldset to validate or null for all fields
   * @return    array          The error messages keyed by field name
   *
   * @example   $arrFieldErrors = FieldValidator::validate( 'User', [ 'id', 'password' => false ] );
   *
   */
  public static function validate( string $strClassName, array | null $arrFieldset = null ) : array {
    $objFields      = BaseObject::getFieldsOfClass( $strClassName );
    $arrFieldErrors = [];

    if( is_null( $arrFieldset ) ) {
      foreach( $objFields as $strFieldName => $objFieldDefinition ) {
        $boolMandatory = isset( $objFieldDefinition->mandatory ) && $objFieldDefinition->mandatory;
        $strError      = self::validateField( $objFieldDefinition, $strFieldName, $boolMandatory );

        if( isset( $strError ) ) $arrFieldErrors[ $strFieldName ] = $strError;
      }

      return $arrFieldErrors;
    }

    foreach( $arrFieldset as $mixFieldsetKey => $mixFieldsetValue ) {
      $strFieldName = is_string( $mixFieldsetKey ) ? $mixFieldsetKey : $mixFieldsetValue;

      if( ! isset( $objFields->$strFieldName ) ) continue;

      $boolMandatory = is_string( $mixFieldsetKey ) ? ( $mixFieldsetValue === true ) : true;
      $strError      = self::validateField( $objFields->$strFieldName, $strFieldName, $boolMandatory );

      if( isset( $strError ) ) $arrFieldErrors[ $strFieldName ] = $strError;
    }

    return $arrFieldErrors;
  }

  /**
   * This method validates the request values of a single field against its
   * field definition and returns the first error message or null. The rule
   * cascade mirrors the frontend order: absence, password companion
   * (the {field}2 parameter), match, minlength, password security,
   * select options, mail. Optional password fields only run when the
   * request carries the field or its companion.
   *
   * @param     object          $objFieldDefinition   The field definition from the class field file
   * @param     string          $strFieldName         The name of the field (request parameter)
   * @param     boolean         $boolMandatory        True when the field must be present
   * @return    string|null     The first error message of the field or null
   *
   * @example   $strError = FieldValidator::validateField( $objFieldDefinition, 'password', true );
   *
   */
  private static function validateField( object $objFieldDefinition, string $strFieldName, bool $boolMandatory ) : string | null {
    $strValue        = Request::param( $strFieldName );
    $boolIsPassword  = isset( $objFieldDefinition->type ) && $objFieldDefinition->type == 'password';
    $strValueRepeat  = $boolIsPassword ? Request::param( $strFieldName . '2' ) : null;

    if( is_null( $strValue ) && ( ! $boolIsPassword || is_null( $strValueRepeat ) ) ) {
      if( ! $boolMandatory ) return null;

      return self::message( $objFieldDefinition, $strFieldName, $boolIsPassword ? 'passwordRepeat' : 'mandatory' );
    }

    if( $boolIsPassword ) {
      if( is_null( $strValue ) || is_null( $strValueRepeat ) ) return self::message( $objFieldDefinition, $strFieldName, 'passwordRepeat' );
      if( $strValue !== $strValueRepeat ) return self::message( $objFieldDefinition, $strFieldName, 'passwordMatch' );
    }

    if( isset( $objFieldDefinition->minLength ) && strlen( $strValue ) < (int) $objFieldDefinition->minLength ) {
      return self::message( $objFieldDefinition, $strFieldName, 'minLength', [ 'minLength' => $objFieldDefinition->minLength ] );
    }

    if( $boolIsPassword && isset( $objFieldDefinition->validatePasswordSecurity ) && $objFieldDefinition->validatePasswordSecurity ) {
      if( ! self::validatePassword( $strValue ) ) return self::message( $objFieldDefinition, $strFieldName, 'passwordSecurity' );
    }

    if( isset( $objFieldDefinition->element ) && $objFieldDefinition->element == 'select' && isset( $objFieldDefinition->options ) ) {
      if( ! property_exists( $objFieldDefinition->options, $strValue ) ) return self::message( $objFieldDefinition, $strFieldName, 'select' );
    }

    if( isset( $objFieldDefinition->mail ) && $objFieldDefinition->mail && ! filter_var( $strValue, FILTER_VALIDATE_EMAIL ) ) {
      return self::message( $objFieldDefinition, $strFieldName, 'mail' );
    }

    return null;
  }

  /**
   * This method builds the error message of a rule: the per field override
   * from the messages entry of the field definition wins over the class
   * template. The placeholder {field} is replaced by the nameDe attribute
   * (fallback: the field name), all other placeholders by the given rule
   * parameters (e.g. {minLength}).
   *
   * @param     object    $objFieldDefinition   The field definition from the class field file
   * @param     string    $strFieldName         The name of the field (fallback for the label)
   * @param     string    $strRule              The rule name (key of the MESSAGES constant)
   * @param     array     $arrPlaceholder       Additional rule parameters for the template
   * @return    string    The assembled error message
   *
   * @example   $strMessage = FieldValidator::message( $objFieldDefinition, 'password', 'passwordMatch' );
   *
   */
  private static function message( object $objFieldDefinition, string $strFieldName, string $strRule, array $arrPlaceholder = [] ) : string {
    $strFieldLabel = isset( $objFieldDefinition->nameDe ) ? $objFieldDefinition->nameDe : $strFieldName;
    $strMessage    = isset( $objFieldDefinition->messages ) && isset( $objFieldDefinition->messages->$strRule )
      ? $objFieldDefinition->messages->$strRule
      : self::MESSAGES[ $strRule ];

    $arrPlaceholder[ 'field' ] = $strFieldLabel;

    foreach( $arrPlaceholder as $strKey => $mixValue ) {
      $strMessage = str_replace( '{' . $strKey . '}', strval( $mixValue ), $strMessage );
    }

    return $strMessage;
  }

  /**
   * This static method validates a password against the password rules
   * from configs.json: minimum length and the required character groups
   * (capital letters, lowercase letters, numbers, special characters).
   *
   * @param     string    $strPassword   The password to validate
   * @return    boolean   True when the password passes every rule, false otherwise
   *
   * @example   $boolValid = FieldValidator::validatePassword( $strPassword );
   *
   */
  private static function validatePassword( string $strPassword ) : bool {
    $objPasswordRules = BaseObject::config()->passwordRules();

    if( $objPasswordRules->passwordMinLength > 0 && strlen( $strPassword ) < $objPasswordRules->passwordMinLength ) return false;
    if( $objPasswordRules->passwordHasCapitalLetters && ! preg_match( '/[A-Z]/', $strPassword ) ) return false;
    if( $objPasswordRules->passwordHasLowercaseLetters && ! preg_match( '/[a-z]/', $strPassword ) ) return false;
    if( $objPasswordRules->passwordHasNumbers && ! preg_match( '/[0-9]/', $strPassword ) ) return false;
    if( $objPasswordRules->passwordHasSpecialCharacters && ! preg_match( '/[^A-Za-z0-9]/', $strPassword ) ) return false;

    return true;
  }

}

// EOF
