/**
 * This suite is the regression test for the registry core with its
 * event semantics (redesign of the ready events 0.2.11). It loads
 * registry.js over new Function() (the class file is browser style
 * without module export) and checks:
 * - the map of maps keys (lower case class names, BLUEPRINTID const)
 * - the instance events: onReady with id = one-time delivery incl.
 *   replay, the callback fires exactly once per event
 * - the class-wide events (id null): permanent subscription with
 *   full replay, blueprints stay silent class-wide
 * - the app ready channel: storage, dispatch with the app instance
 *   and replay for late listeners
 * - _dispatchSafely: a callback exception does not abort the sister
 *   listeners
 * It runs in node without any browser dependency — same pattern as
 * the PHP suites: OK line per check, exit code 1 on the first error.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-24
 *
 * @example   node includes/tests/registryTest.js
 *
 */

'use strict';

const objFileSystem       = require( 'fs' );
const strRegistrySource   = objFileSystem.readFileSync( __dirname + '/../js/registry.js', 'utf8' );
const Registry            = new Function( strRegistrySource + '\nreturn Registry;' )();

/**
 * This function is the regression check helper of the suite: a passing
 * condition prints the OK line, a failing condition prints the
 * FEHLER line and stops the suite with exit code 1.
 *
 * @param     {boolean}    boolCondition   The condition to check
 * @param     {string}     strMessage      The check description printed in both cases
 * @return    {void}
 *
 * @example   check( registry.get( 'user' ) === undefined, 'get: unbekannte Klasse' );
 *
 */
function check( boolCondition, strMessage ) {
  if( ! boolCondition ) {
    console.log( 'FEHLER: ' + strMessage );
    process.exit( 1 );
  }

  console.log( 'OK: ' + strMessage );

  return;
}

const objRegistry = new Registry();

// --- Konstanten und Schlüssel-Normalisierung ---

check( Registry.BLUEPRINTID === 'new', 'Konstante: BLUEPRINTID ist new' );
check( Registry.EVENTNAMEAPPREADY === '__app_ready__', 'Konstante: EVENTNAMEAPPREADY ist gesetzt' );

// --- register, get, getAll, has (Map of Maps, Blueprint-Filter) ---

const objFirst  = { name: 'first' };
const objSecond = { name: 'second' };

objRegistry.register( 'User', Registry.BLUEPRINTID, { name: 'blueprint' } );

check( objRegistry.getAll( 'User' ).size === 0 && objRegistry.get( 'User', 'new' ) !== undefined, 'getAll: Blueprint gefiltert, direkter Zugang über get bleibt' );

// --- Instanz-Events: onReady mit Id = Einmal-Zustellung mit Replay ---

const arrOnceBefore = [];

objRegistry.onReady( 'Role', 'roleA', ( objInstance ) => arrOnceBefore.push( objInstance.name ) );
objRegistry.register( 'Role', 'roleA', { name: 'alpha' } );

check( arrOnceBefore.length === 1 && arrOnceBefore[ 0 ] === 'alpha', 'onReady mit Id: Listener vor der Registration feuert bei register' );

const arrOnceReplay = [];

objRegistry.onReady( 'Role', 'roleA', ( objInstance ) => arrOnceReplay.push( objInstance.name ) );

check( arrOnceReplay.length === 1 && arrOnceReplay[ 0 ] === 'alpha', 'onReady mit Id: Listener nach der Registration bekommt den Replay sofort' );

objRegistry.register( 'Role', 'roleB', { name: 'beta' } );

check( arrOnceBefore.length === 1 && arrOnceReplay.length === 1, 'onReady mit Id: Once-Callback feuert genau einmal pro Event (fremde Id feuert nicht nach)' );

// --- Klassen-Events: onReady ohne Id = permanente Subscription mit vollem Replay ---

const arrClassEarly = [];

objRegistry.onReady( 'Weed', null, ( objInstance ) => arrClassEarly.push( objInstance.name ) );

objRegistry.register( 'Weed', 'weedA', { name: 'one' } );
objRegistry.register( 'Weed', 'weedB', { name: 'two' } );
objRegistry.register( 'Weed', Registry.BLUEPRINTID, { name: 'blueprint' } );

check( arrClassEarly.length === 2 && arrClassEarly[ 0 ] === 'one' && arrClassEarly[ 1 ] === 'two', 'Klassenweit: einmal registriert feuert die Subscription pro neuer Instanz' );

const arrClassLate = [];

objRegistry.onReady( 'Weed', null, ( objInstance ) => arrClassLate.push( objInstance.name ) );

check( arrClassLate.length === 2 && arrClassLate[ 0 ] === 'one' && arrClassLate[ 1 ] === 'two', 'Klassenweit: Late-Listener bekommt den vollen Replay (Blueprint ausgespart)' );

objRegistry.register( 'Weed', 'weedC', { name: 'three' } );

check( arrClassEarly.length === 3 && arrClassLate.length === 3, 'Klassenweit: Subscription bleibt für spätere Instanzen permanent' );

// --- App-Ready-Kanal: Speicherung, Dispatch mit App-Instanz, Replay ---

objRegistry.register( 'App', 'JPEnvelope_test', { name: 'app' } );

const arrAppBefore = [];

objRegistry.onAppReady( ( app ) => arrAppBefore.push( app.name ) );
objRegistry.setAppReady();

const objApp = objRegistry.get( 'app' );

check( arrAppBefore.length === 1 && arrAppBefore[ 0 ] === 'app', 'AppReady: gespeicherter Callback feuert mit der App-Instanz' );

const arrAppAfter = [];

objRegistry.onAppReady( ( app ) => arrAppAfter.push( app.name ) );

check( arrAppAfter.length === 1 && arrAppAfter[ 0 ] === 'app' && objApp !== undefined, 'AppReady: Late-Listener bekommt den Replay' );

// --- _dispatchSafely: Schwester-Risiko (eigene Registry ohne App-Kanal) ---

const objRegistrySisters  = new Registry();
const objErrorOutput      = console.error;
const arrSisters          = [];

console.error = () => {};

try {
  objRegistrySisters._dispatchSafely( null, () => { throw new Error( 'Test-Exception' ); }, () => arrSisters.push( true ) );
} finally {
  console.error = objErrorOutput;
}

check( arrSisters.length === 1, '_dispatchSafely: Exception im ersten Callback bricht die Schwester-Callbacks nicht ab' );

console.log( 'Alle Tests bestanden.' );
