<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );

/**
 * This class is the single access point for GET and POST parameters.
 * Raw access to $_GET/$_POST is forbidden outside this class.
 * Validation rules (type, whitelist, pattern, default) are declared
 * declaratively in json/data/params.json — one branch per view plus the 'global'
 * branch for everything before the view resolution (auth, view, id, class).
 * Undeclared parameters do not exist (fail-closed, silent); a misconfigured
 * params.json on the other hand fails loudly.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-19
 *
 * @example   $strId = Request::param( 'id' );
 *
 */
class Request {

  /**
   * The file path of the parameter rules file (params.json).
   *
   * @var       string
   *
   */
  const PARAMSJSONFILE = BaseObject::FILEPATHJSON . 'data/params.json';

  /**
   * The decoded rules tree of params.json, loaded lazily on first access.
   *
   * @var       object|null
   *
   */
  private static ?object $objRules = null;

  /**
   * The name of the active view branch within params.json
   * (set by Controller::init() after the view resolution via setScope()).
   *
   * @var       string|null
   *
   */
  private static ?string $strScope = null;

  /**
   * The value cache per parameter name (survives $_GET changes;
   * cleared by setScope() and reset()).
   *
   * @var       array
   *
   */
  private static array $arrCache   = [];

  /**
   * This method returns the validated value of a GET or POST parameter.
   * Only declared parameters resolve (global or the active view branch);
   * undeclared parameters silently return null (fail-closed). The result
   * is cached per parameter name. GET takes precedence over POST, empty
   * strings and arrays count as absent.
   *
   * @param     string    $strName   The name of the parameter
   * @return    mixed     The validated value, the configured default or null
   *
   * @example   $strId = Request::param( 'id' );
   *
   */
  public static function param( string $strName ) : mixed {
    self::loadRules();

    if( array_key_exists( $strName, self::$arrCache ) ) return self::$arrCache[ $strName ];

    $objRule  = self::getRule( $strName );
    $mixValue = isset( $objRule ) ? self::applyRule( $strName, $objRule ) : null;

    self::$arrCache[ $strName ] = $mixValue;

    return $mixValue;
  }

  /**
   * This method sets the parameter scope to the branches 'global' and the
   * named view branch — after the call only these two branches resolve.
   * The value cache is cleared because the scope change alters the resolution.
   * A missing view branch in params.json throws a RuntimeException (fail-loud).
   *
   * @param     string    $strViewName   The view name (config key in views.json)
   * @return    void
   *
   * @throws    RuntimeException   When params.json has no branch for the view
   *
   * @example   Request::setScope( 'viewUserList' );
   *
   */
  public static function setScope( string $strViewName ) : void {
    self::loadRules();

    if( ! isset( self::$objRules->$strViewName ) ) throw new RuntimeException( 'Request: kein params.json-Zweig für View "' . $strViewName . '"' );

    self::$strScope = $strViewName;
    self::$arrCache = [];

    return;
  }

  /**
   * This method clears the rules, the scope and the value cache.
   * It is used by the test suites to switch between scenarios.
   *
   * @return    void
   *
   * @example   Request::reset();
   *
   */
  public static function reset() : void {
    self::$objRules = null;
    self::$strScope = null;
    self::$arrCache = [];

    return;
  }

  /**
   * This method loads and caches the rules tree of params.json on first access.
   * An unreadable file or a missing 'global' branch throws a RuntimeException
   * (fail-loud: every misconfiguration of params.json is a bug, never a
   * runtime input case).
   *
   * @return    void
   *
   * @throws    RuntimeException   When params.json is unreadable or has no global branch
   *
   * @example   Request::param( 'id' ); // triggers loadRules() lazily
   *
   */
  private static function loadRules() : void {
    if( isset( self::$objRules ) ) return;

    $objRules = BaseObject::loadFileDeCrypted( self::PARAMSJSONFILE );

    if( ! isset( $objRules ) || ! isset( $objRules->global ) ) throw new RuntimeException( 'Request: params.json unlesbar oder ohne global-Zweig' );

    self::$objRules = $objRules;

    return;
  }

  /**
   * This method returns the rule object of a parameter. It looks in the
   * 'global' branch first, then in the active view branch. Undeclared
   * parameters return null (fail-closed).
   *
   * @param     string          $strName   The name of the parameter
   * @return    object|null      The rule object or null
   *
   * @example   $objRule = Request::getRule( 'id' );
   *
   */
  private static function getRule( string $strName ) : ?object {
    if( isset( self::$objRules->global->$strName ) ) return self::$objRules->global->$strName;
    if( isset( self::$strScope ) && isset( self::$objRules->{self::$strScope}->$strName ) ) return self::$objRules->{self::$strScope}->$strName;

    return null;
  }

  /**
   * This method applies the rule of a parameter to the raw value and
   * dispatches to the type validators (string, int, float). The rule
   * type decides the validation path; an unknown type throws a
   * RuntimeException (fail-loud misconfiguration guard).
   *
   * @param     string     $strName      The name of the parameter
   * @param     object     $objRule      The rule object of the parameter
   * @return    mixed      The validated value or the configured default
   *
   * @throws    RuntimeException   When the rule has an unknown type
   *
   * @example   $mixValue = Request::applyRule( 'id', $objRule );
   *
   */
  private static function applyRule( string $strName, object $objRule ) : mixed {
    $mixDefault = $objRule->default ?? null;
    $strType    = $objRule->type ?? null;

    $strRaw = self::getRawValue( $strName );

    if( ! isset( $strRaw ) ) return $mixDefault;

    return match( $strType ) {
      'string' => self::validateString( $strRaw, $objRule, $mixDefault ),
      'int'    => self::validateInt( $strRaw, $objRule, $mixDefault ),
      'float'  => self::validateFloat( $strRaw, $objRule, $mixDefault ),
      default  => throw new RuntimeException( 'Request: unbekannter Typ "' . $strType . '" für Parameter "' . $strName . '"' ),
    };
  }

  /**
   * This method reads the raw value of a parameter from the superglobals.
   * $_GET takes precedence over $_POST; empty strings and non-string
   * values (e.g. arrays) count as absent and return null.
   *
   * @param     string          $strName   The name of the parameter
   * @return    string|null     The raw value or null
   *
   * @example   $strRaw = Request::getRawValue( 'user' );
   *
   */
  private static function getRawValue( string $strName ) : ?string {
    if( isset( $_GET[ $strName ] ) && is_string( $_GET[ $strName ] ) && $_GET[ $strName ] != '' ) return $_GET[ $strName ];
    if( isset( $_POST[ $strName ] ) && is_string( $_POST[ $strName ] ) && $_POST[ $strName ] != '' ) return $_POST[ $strName ];

    return null;
  }

  /**
   * This method validates a raw string value against the rule: the PCRE
   * pattern (mandatory match) and the value whitelist (strict comparison).
   * A failing check falls back to the configured default instead of
   * rejecting the request (fail-closed with a well-defined value).
   *
   * @param     string    $strRaw       The raw value
   * @param     object    $objRule      The rule object of the parameter
   * @param     mixed     $mixDefault   The configured default value
   * @return    mixed     The validated string or the default
   *
   * @example   $mixValue = Request::validateString( $strRaw, $objRule, $mixDefault );
   *
   */
  private static function validateString( string $strRaw, object $objRule, mixed $mixDefault ) : mixed {
    if( isset( $objRule->pattern ) && ! preg_match( $objRule->pattern, $strRaw ) ) return $mixDefault;
    if( isset( $objRule->values ) && ! in_array( $strRaw, $objRule->values, true ) ) return $mixDefault;

    return $strRaw;
  }

  /**
   * This method validates a raw int value against the rule: the value must
   * be a valid integer (filter_var) and pass the value whitelist (strict
   * comparison). Otherwise the configured default is returned.
   *
   * @param     string    $strRaw       The raw value
   * @param     object    $objRule      The rule object of the parameter
   * @param     mixed     $mixDefault   The configured default value
   * @return    mixed     The validated int or the default
   *
   * @example   $mixValue = Request::validateInt( $strRaw, $objRule, $mixDefault );
   *
   */
  private static function validateInt( string $strRaw, object $objRule, mixed $mixDefault ) : mixed {
    $intValue = filter_var( $strRaw, FILTER_VALIDATE_INT );

    if( $intValue === false ) return $mixDefault;
    if( isset( $objRule->values ) && ! in_array( $intValue, $objRule->values, true ) ) return $mixDefault;

    return $intValue;
  }

  /**
   * This method validates a raw float value against the rule: the value must
   * be a valid float (filter_var) and pass the value whitelist (strict
   * comparison). Otherwise the configured default is returned.
   *
   * @param     string    $strRaw       The raw value
   * @param     object    $objRule      The rule object of the parameter
   * @param     mixed     $mixDefault   The configured default value
   * @return    mixed     The validated float or the default
   *
   * @example   $mixValue = Request::validateFloat( $strRaw, $objRule, $mixDefault );
   *
   */
  private static function validateFloat( string $strRaw, object $objRule, mixed $mixDefault ) : mixed {
    $floatValue = filter_var( $strRaw, FILTER_VALIDATE_FLOAT );

    if( $floatValue === false ) return $mixDefault;
    if( isset( $objRule->values ) && ! in_array( $floatValue, $objRule->values, true ) ) return $mixDefault;

    return $floatValue;
  }

}

// EOF
