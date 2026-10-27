<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );

/**
 * This class renders the output of the app: it assigns template variables
 * and processes the templates (simple {{class::property}} replacement —
 * objects and arrays are json encoded, unreplaced placeholders are removed
 * from the output). In addition it provides static helpers around cookies,
 * logging, mail delivery and formatting that the presentation and the auth
 * share. Most methods guard CLI runs of the regression suites.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-12
 *
 * @example   $objPresentation = new Presentation();
 *            echo $objPresentation->processTemplate( 'header.tmpl' );
 *
 */
class Presentation extends BaseObject {

  /**
   * The assigned template variables, keyed by their placeholder name
   * ("class::property" or "class::Translate::key").
   *
   * @var       object
   *
   */
  protected object $templateVars;

  /**
   * This method is the constructor of the class.
   * It initializes the template variable container.
   *
   * @param     string|null   $strId   The id or null (presentation is id-less)
   * @return    void
   *
   * @example   $objPresentation = new Presentation();
   *
   */
  public function __construct( string | null $strId = null ) {
    $this->templateVars = new stdClass();

    parent::__construct( $strId );

    return;
  }

  /**
   * This method assigns a template variable. The property 'Translate' is
   * the special case: the object is flattened into one variable per key
   * ("class::Translate::key"), so that keys become placeholders
   * themselves. Every other value is stored under "class::property"
   * as a string; objects and arrays are json encoded.
   *
   * @param     string    $strClass      The class scope of the placeholder
   * @param     string    $strProperty   The property name of the placeholder
   * @param     mixed     $mixValue      The value to assign
   * @return    void
   *
   * @example   $objPresentation->assignTemplateVar( 'config', 'Translate', $objConfig->serializeObject() );
   *
   */
  public function assignTemplateVar( string $strClass, string $strProperty, mixed $mixValue = null ) : void {
    if( $strProperty == 'Translate' ) {
      foreach( $mixValue as $strKey => $strValue ) {
        $strTemplateVarName                       = $strClass . '::' . $strProperty . '::' . $strKey;
        $this->templateVars->$strTemplateVarName  = $strValue;
      }
    } else {
      $strTemplateVarName                      = $strClass . '::' . $strProperty;
      $mixValue                                =  gettype( $mixValue ) == 'object' || gettype( $mixValue ) == 'array' ? json_encode( $mixValue ) : strval( $mixValue );
      $this->templateVars->$strTemplateVarName = $mixValue;
    }

    return;
  }

  /**
   * This method processes a template: it reads the template file, replaces
   * every assigned placeholder {{class::property}} with its value (objects
   * and arrays are json encoded) and removes every placeholder that stayed
   * unreplaced from the output.
   *
   * @param     string    $strTemplateName   The file name of the template
   * @return    string    The processed template output
   *
   * @example   $strContent = $objPresentation->processTemplate( 'user.list.tmpl' );
   *
   */
  public function processTemplate( string $strTemplateName ) : string {
    $strContent = file_get_contents( Presentation::FILEPATHTEMPLATES . $strTemplateName );

    foreach( $this->templateVars as $strProperty => $mixValue ) {
      if( gettype( $mixValue ) == 'object' || gettype( $mixValue ) == 'array' ) $mixValue = json_encode( $mixValue );
      $strContent = str_replace( '{{' . $strProperty . '}}', strval( $mixValue ), $strContent );
    }

    return preg_replace( '/\{\{.*?\}\}/s', '', $strContent );
  }

  /**
   * This static method writes the auth cookie. Existing cookie values are
   * merged, the cookie expires after 30 days. CLI runs of the regression
   * suites return early: without HTTP headers setcookie would only be a
   * warning — the login there continues via getUserFromId anyway.
   *
   * @param     object    $objParams   The cookie values to write (e.g. the auth token)
   * @return    void
   *
   * @example   Presentation::writeCookie( $objParams );
   *
   */
  public static function writeCookie( object $objParams ) : void {
    if( php_sapi_name() === 'cli' ) return;

    $strCookieName  = BaseObject::config()->cookieName();
    $objCookie      = new stdClass();

    if( isset( $_COOKIE ) && isset( $_COOKIE[ $strCookieName ] ) ) $objCookie = json_decode( $_COOKIE[ $strCookieName ] );

    foreach( $objParams as $strParam => $strValue ) {
      $objCookie->$strParam = $strValue;
    }

    setcookie( $strCookieName, json_encode( $objCookie ), [ 'expires' => time() + 60 * 60 * 24 * 30, 'path' => '/' ] );

    return;
  }

  /**
   * This static method deletes the auth cookie by expiring it.
   * CLI runs of the regression suites return early (same pattern
   * as writeCookie()).
   *
   * @return    void
   *
   * @example   Presentation::deleteCookie();
   *
   */
  public static function deleteCookie() : void {
    if( php_sapi_name() === 'cli' ) return;

    $strCookieName  = BaseObject::config()->cookieName();

    setcookie( $strCookieName, '', [ 'expires' => time() - 3600, 'path' => '/' ] );

    return;
  }

  /**
   * This static method returns a property from the auth cookie
   * or null when the cookie or the property is missing.
   *
   * @param     string          $strCookieProperty   The property name in the cookie
   * @return    string|null     The cookie value or null
   *
   * @example   $strToken = Presentation::getCookieProperty( 'token' );
   *
   */
  public static function getCookieProperty( string $strCookieProperty ) : string | null {
    $strCookieName  = BaseObject::config()->cookieName();
    $objCookie      = null;

    if( isset( $_COOKIE ) && isset( $_COOKIE[ $strCookieName ] ) ) $objCookie = json_decode( $_COOKIE[ $strCookieName ] );

    return isset( $objCookie ) && isset( $objCookie->$strCookieProperty ) ? $objCookie->$strCookieProperty : null;
  }

  /**
   * This static method appends a message to the log file
   * (default includes/logs/messages.log) with an optional timestamp.
   * Objects and arrays are json encoded.
   *
   * @param     string|array|object    $strMessage       The message to log
   * @param     boolean                $boolTimestamp    Prepend a timestamp (default true)
   * @param     string|null           $strFile          The log file path or null for the default
   * @return    void
   *
   * @example   Presentation::logToFile( 'Something failed' );
   *
   */
  public static function logToFile( string | array | object $strMessage, bool $boolTimestamp = true, string | null $strFile = null  ) : void {
    $strFile    = isset( $strFile ) ? $strFile : __DIR__ . '/../logs/messages.log';
    $strMessage = is_object( $strMessage ) || is_array( $strMessage ) ? json_encode( $strMessage ) : strval( $strMessage );
    $strMessage = $boolTimestamp ? date( "Y-m-d H:i:s" ) . ' ' . $strMessage : $strMessage;

    file_put_contents( $strFile, $strMessage . PHP_EOL, FILE_APPEND | LOCK_EX );

    return;
  }

  /**
   * This static method converts a datetime string into a Unix timestamp.
   *
   * @param     string    $strTimestamp   The datetime string
   * @return    int       The Unix timestamp
   *
   * @example   $intTimestamp = Presentation::stringToTimestamp( '2026-10-23 12:00:00' );
   *
   */
  public static function stringToTimestamp ( string $strTimestamp ) : int {
    return strtotime( $strTimestamp );
  }

  /**
   * This static method formats a Unix timestamp as a German datetime
   * string (d.m.Y H:i).
   *
   * @param     int       $intTimestamp   The Unix timestamp
   * @return    string    The formatted datetime string
   *
   * @example   $strDate = Presentation::timestampToString( $intTimestamp );
   *
   */
  public static function timestampToString( int $intTimestamp ) : string {
    return date( 'd.m.Y H:i', $intTimestamp );
  }

  /**
   * This static method builds a form error object (field name plus
   * message) as used in the form validation.
   *
   * @param     string    $strField     The name of the form field
   * @param     string    $strMessage   The error message
   * @return    object    The form error object
   *
   * @example   $objFormError = Presentation::newFormError( 'password', 'Zu kurz.' );
   *
   */
  public static function newFormError( string $strField, string $strMessage ) : object {
    $objFormError          = new stdClass();
    $objFormError->field   =  $strField;
    $objFormError->message = $strMessage;

    return $objFormError;
  }

  /**
   * This static method sends an HTML mail. With the multipart flag the
   * message is sent with a generated boundary (the {{BOUNDARY}}
   * placeholder in the message is replaced), otherwise as plain HTML
   * mail. The envelope sender is derived from the (regex-cleaned)
   * mail address; a failed delivery is logged.
   *
   * @param     object    $objEmail         The email object (mailAddress, to, subject, message, optional cc/bcc)
   * @param     boolean   $boolMultipart    Send as multipart mail with boundary
   * @return    void
   *
   * @example   Presentation::sendHtmlMail( $objEmail, false );
   *
   */
  public static function sendHtmlMail( object $objEmail, bool $boolMultipart ) : void {
    $arrHeader[] = 'MIME-Version: 1.0';
    $arrHeader[] = 'From: ' . $objEmail->mailAddress;

    if( $boolMultipart ) {
      $strBoundary        = md5( uniqid( 'boundary', true ) );
      $arrHeader[]        = "Content-Type: multipart/mixed; boundary=\"" . $strBoundary . "\"\r\n";
      $objEmail->message  = str_replace( '{{BOUNDARY}}', $strBoundary, $objEmail->message );
    } else {
      $arrHeader[] = 'Content-type: text/html; charset=utf-8';
    }

    if( isset( $objEmail->cc ) ) $arrHeader[] = 'Cc: ' . $objEmail->cc;
    if( isset( $objEmail->bcc ) ) $arrHeader[] = 'Bcc: ' . $objEmail->bcc;

    preg_match( '/[\w\.-]+@[\w\.-]+\.\w+/', $objEmail->mailAddress, $arrMatches );

    $strCleanEmail    = isset( $arrMatches[ 0 ] ) ? $arrMatches[ 0 ] : $objEmail->mailAddress;
    $strEnvelopeFrom  = '-f ' . $strCleanEmail;

    $boolSent = mail( $objEmail->to, $objEmail->subject, $objEmail->message, implode( "\r\n", $arrHeader ), $strEnvelopeFrom );

    if( ! $boolSent ) Presentation::logToFile( 'Mail Versand fehlgeschlagen: To=' . $objEmail->to . ' | Subject=' . $objEmail->subject );

    return;
  }

  /**
   * This method shortens a text to the given length, optionally with
   * trailing three points (which count towards the length).
   *
   * @param     string    $strText       The text to shorten
   * @param     boolean   $boolPoints    Append '...' when the text was shortened
   * @param     int       $intLength    The maximum length
   * @return    string    The (possibly shortened) text
   *
   * @example   $strShort = $objPresentation->shortText( $strText, true, 50 );
   *
   */
  public function shortText( string $strText, bool $boolPoints, int $intLength ) : string {
    if( strlen( $strText ) > $intLength ) return $boolPoints ? substr( $strText, 0, $intLength - 3 ) . '...' : substr( $strText, 0, $intLength );

    return $strText;
  }

  /**
   * This static method cleans a string for use in id attributes
   * ('at', 'punkt' and '_' as replacements — the JS counterpart
   * is validateId()).
   *
   * @param     string    $strContent   The string to clean
   * @return    string    The cleaned string
   *
   * @example   $strCleanId = Presentation::cleanId( 'user@example.com' );
   *
   */
  public static function cleanId( string $strContent ) : string {
    $strContent = str_replace( '@', 'at', $strContent );
    $strContent = str_replace( '.', 'punkt', $strContent );
    $strContent = str_replace( ' ', '_', $strContent );

    return $strContent;
  }

  /**
   * This static method escapes a text for the iCalendar format
   * (backslashes, semicolons, commas, line breaks; null becomes
   * an empty string).
   *
   * @param     string|null   $strValue   The text to escape
   * @return    string        The escaped iCal text
   *
   * @example   $strIcalText = Presentation::escapeIcalText( $strValue );
   *
   */
  public static function escapeIcalText( ?string $strValue ) : string {
    $strValue = $strValue ?? '';
    $strValue = str_replace( '\\', '\\\\', $strValue );
    $strValue = str_replace( ';',  '\\;',  $strValue );
    $strValue = str_replace( ',',  '\\,',  $strValue );
    $strValue = str_replace( "\n", '\\n',  $strValue );
    $strValue = str_replace( "\r", '',     $strValue );

    return $strValue;
  }

  /**
   * This method sends the JSON content-type header for json views.
   * CLI runs of the regression suites return early: header() after the
   * first check() echoes would only print the 'headers already sent'
   * warning — same pattern as writeCookie()/deleteCookie().
   *
   * @return    void
   *
   * @example   $objPresentation->getJsonHeader();
   *
   */
  public function getJsonHeader() : void {
    if( php_sapi_name() === 'cli' ) return;

    header( 'Content-type: application/json' );
    return;
  }

  /**
   * This method sends the JavaScript content-type header.
   *
   * @return    void
   *
   * @example   $objPresentation->getJavaScriptHeader();
   *
   */
  public function getJavaScriptHeader() : void {
    header( 'Content-type: text/javascript' );
    return;
  }

  /**
   * This static method returns the file names of a directory,
   * optionally without their extension. A missing directory
   * returns an empty array; '.' and '..' are skipped.
   *
   * @param     string    $strDirectoryPath        The directory to read
   * @param     boolean   $boolWithoutExtension    Strip the file extension (default false)
   * @return    array     The file names
   *
   * @example   $arrFiles = Presentation::getFileNamesFromDirectory( 'includes/templates' );
   *
   */
  public static function getFileNamesFromDirectory( string $strDirectoryPath, bool $boolWithoutExtension = false ) : array {
    if( ! is_dir( $strDirectoryPath ) ) return [];

    $arrFiles  = scandir( $strDirectoryPath );
    $arrResult = [];

    foreach( $arrFiles as $strFileName ) {
      if( $strFileName == '.' ||  $strFileName == '..' ) continue;

      $strFileName = $boolWithoutExtension ? pathinfo( $strFileName, PATHINFO_FILENAME ) : $strFileName;

      array_push( $arrResult, $strFileName );
    }

    return $arrResult;
  }

}

// EOF
