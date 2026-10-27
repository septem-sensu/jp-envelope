/**
 * The Utils class only contains static methods to make work easier.
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-06-18
 *
 * @example   const guid = Utils.guid();
 *
 */
class Utils {

/**
 * Global, static register to avoid multiple registrations of event handlers.
 * Prevents double bindings, even with multiple class instances.
 *
 * @static
 *
 * @type      {Object<string, boolean>}
 *
 * @example   if( Utils.globalRegisteredEvents[ 'Gameplay' ] ) return;
 * Utils.globalRegisteredEvents[ 'Gameplay' ] = true;
 *
 */
  static globalRegisteredEvents = {};

/**
 * This static method creates a GUID.
 *
 * @public
 *
 * @return    {string}  guid  A GUID
 *
 * @example   const guid = Utils.guid();
 *
 */
  static guid() {
    return this.s4() + this.s4() + '-' + this.s4() + '-' + this.s4() + '-' + this.s4() + '-' + this.s4() + this.s4() + this.s4();
  }

/**
 * This static method generates a random string.
 *
 * @public
 *
 * @return    {string}  randomString  A random string
 *
 * @example   const randomString = Utils.s4();
 *
 */
  static s4() {
    return Math.floor( ( 1 + Math.random() ) * 0x10000 ).toString( 16 ).substring( 1 );
  }

/**
 * This static method converts a datetime string into a Php timestamp.
 *
 * @public
 *
 * @param     {string}  dateTimeString   The date time string
 * @return    {number}  timestamp        The Php Timestamp
 *
 * @example   const timestamp = Utils.stringToPhpTimestamp( dateTimeString );
 *
 */
  static stringToPhpTimestamp( dateTimeString ) {
    const dateTime = new Date( dateTimeString );

    return Math.floor( dateTime.getTime() / 1000 );
  }

/**
 * This static method converts a datetime string into a JavaScript timestamp.
 *
 * @public
 *
 * @param     {string}  dateTimeString   The date time string
 * @return    {number}  timestamp        The JavaScript Timestamp
 *
 * @example   const timestamp = Utils.stringToJsTimestamp( dateTimeString );
 *
 */
  static stringToJsTimestamp( dateTimeString ) {
    const dateTime = new Date( dateTimeString );

    return dateTime.getTime();
  }

/**
 * This static method converts a Php timestamp into a human-readable format.
 *
 * @public
 *
 * @param     {number}   timestamp       The Php Timestamp
 * @param     {boolean}  seconds         Should the seconds be returned for the time with true or false
 * @param     {string}   format          The return format, date for just the date, time for just the time, or datetime for both the date and time
 * @return    {string}   dateTimeString  The date time string
 *
 * @example   const dateTimeString = Utils.timestampPhpToString( timestamp, seconds, format );
 *
 */
  static timestampPhpToString( timestamp, seconds, format ) {
    return this.timestampJsToString( timestamp * 1000, seconds, format  );
  }

/**
 * This static method converts a JavaScript timestamp into a human-readable format.
 *
 * @public
 *
 * @param     {number}   timestamp       The JavaScript Timestamp
 * @param     {boolean}  seconds         Should the seconds be returned for the time with true or false
 * @param     {string}   format          The return format, date for just the date, time for just the time, or datetime for both the date and time
 * @return    {string}   dateTimeString  The date time string
 *
 * @example   const dateTimeString = Utils.timestampJsToString( timestamp, seconds, format );
 *
 */
  static timestampJsToString( timestamp, seconds, format ) {
    const dateTime = new Date( timestamp );
    const pad      = (num) => String( num ).padStart( 2, '0' );
    const date     = pad( dateTime.getDate() ) + '.' + pad( dateTime.getMonth() + 1 ) + '.' + dateTime.getFullYear();
    let time       = pad( dateTime.getHours() ) + ':' + pad( dateTime.getMinutes() );

    time           = seconds ? time + ':' + pad( dateTime.getSeconds() ) : time;

    if( format === 'date' ) {
      return date;
    } else if( format === 'time' ) {
      return time;
    }

    return date + ' ' + time;
  }

/**
 * This static method plays a New Message tone.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   Utils.playMessageBeep();
 *
 */
  static playMessageBeep() {
    const audioCtx        = new ( window.AudioContext || window.webkitAudioContext )();
    const now             = audioCtx.currentTime;
    const osc1            = audioCtx.createOscillator();
    const gain1           = audioCtx.createGain();

    osc1.type             = 'sine';
    osc1.frequency.value  = 1200;

    gain1.gain.setValueAtTime( 0.2, now );
    gain1.gain.exponentialRampToValueAtTime( 0.001, now + 0.1 );
    osc1.connect( gain1 );
    gain1.connect( audioCtx.destination );
    osc1.start( now );
    osc1.stop( now + 0.1 );

    const startSound2     = now + 0.15;
    const osc2            = audioCtx.createOscillator();
    const gain2           = audioCtx.createGain();

    osc2.type             = 'sine';
    osc2.frequency.value  = 800;

    gain2.gain.setValueAtTime( 0.2, startSound2 );
    gain2.gain.exponentialRampToValueAtTime( 0.001, startSound2 + 0.4 );
    osc2.connect( gain2 );
    gain2.connect( audioCtx.destination );
    osc2.start( startSound2 );
    osc2.stop( startSound2 + 0.4 );

    return;
  }

/**
 * This static method triggers a vibration on an Android phone.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   Utils.triggerMessageVibration();
 *
 */
  static triggerMessageVibration() {
    if( 'vibrate' in navigator ) navigator.vibrate( [ 200, 100, 200 ] );

    return;
  }

/**
 * This static method converts the first letter of a string to upper case.
 *
 * @public
 *
 * @param     {string}   content   The input string
 * @return    {string}   content   The string with upper case first letter
 *
 * @example   const className = Utils.firstLetterToUpperCase( 'user' );
 *
 */
  static firstLetterToUpperCase( content ) {
    return String( content ).charAt( 0 ).toUpperCase() + String( content ).slice( 1 );
  }

/**
 * This static method converts the first letter of a string to lower case.
 *
 * @public
 *
 * @param     {string}   content    The input string
 * @return    {string}   content    The string with lower case first letter
 *
 * @example   const fileName = Utils.firstLetterToLowerCase( 'User' );
 *
 */
  static firstLetterToLowerCase( content ) {
    return String( content ).charAt( 0 ).toLowerCase() + String( content ).slice( 1 );
  }

/**
 * This static method returns a value of a url param key from a url or a part of the url.
 *
 * @public
 *
 * @param     {string}   searchString    The url or a part of the url
 * @param     {string}   key             The param name of the value
 * @return    {string}   value           The value of the param
 *
 * @example   const value = Utils.getUrlParamValue( document.location.href, 'view' );
 *
 */
  static getUrlParamValue( searchString, key ) {
    if( typeof searchString != 'string' || searchString == '' || typeof key != 'string' || key == '' ) return '';

    searchString            = searchString.replaceAll( '?', '&' );
    const searchStringParts = searchString.split( '&' );

    for( var i = 0; i < searchStringParts.length; i++ ) {
      if( searchStringParts[ i ].indexOf( key + '=' ) !== 0 ) continue;
      return searchStringParts[ i ].split( '=' )[ 1 ];
    }

    return '';
  }
}
