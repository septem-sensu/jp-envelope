/**
 * This suite is the regression test for the registry core with its
 * event semantics (redesign of the ready events 0.2.11, deferred
 * dispatch 0.2.19). It loads registry.js over new Function() (the
 * class file is browser style without module export) and checks:
 * - the map of maps keys (lower case class names, BLUEPRINTID const)
 * - the deferred dispatch: register() only queues, fireEvents()
 *   delivers (early listeners, late listeners in the queue window,
 *   remove drops pending events)
 * - the instance events: onReady with id = one-time delivery incl.
 *   replay, the callback fires exactly once per event
 * - the class-wide events (id null): permanent subscription with
 *   full replay, blueprints stay silent class-wide
 * - the loadall collection event: fires once per load batch with the
 *   complete instance map, replay for late listeners, remove keeps
 *   the snapshot current
 * - the app ready channel: storage, dispatch with the app instance
 *   and replay for late listeners
 * - remove: instance removal without ghost replays (delete and
 *   blueprint replacement route)
 * - _dispatchSafely: a callback exception does not abort the sister
 *   listeners
 * It runs in node without any browser dependency — same pattern as
 * the PHP suites: OK line per check, exit code 1 on the first error.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.12
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

// --- Deferred Dispatch: register queued, fireEvents liefert ---

const objRegistryDeferred  = new Registry();
const arrDeferredEarly     = [];

objRegistryDeferred.onSave( 'Task', 'taskA', ( objInstance ) => arrDeferredEarly.push( objInstance.name ) );

objRegistryDeferred.register( 'Task', 'taskA', { name: 'queued' }, 'save' );

check( arrDeferredEarly.length === 0, 'Deferred: register queue das Event, es feuert noch nicht' );

const arrDeferredWindow = [];

objRegistryDeferred.onSave( 'Task', 'taskA', ( objInstance ) => arrDeferredWindow.push( objInstance.name ) );

check( arrDeferredWindow.length === 0, 'Deferred: Listener im Queue-Fenster bekommt vor dem Flush keinen Replay' );

objRegistryDeferred.fireEvents();

check( arrDeferredEarly.length === 1 && arrDeferredEarly[ 0 ] === 'queued', 'Deferred: fireEvents liefert das frühe Event nach' );
check( arrDeferredWindow.length === 1 && arrDeferredWindow[ 0 ] === 'queued', 'Deferred: fireEvents bedient auch den Listener aus dem Queue-Fenster' );

const objRegistryPending = new Registry();
const arrPendingTrack    = [];

objRegistryPending.onDelete( 'Task', 'taskB', ( objInstance ) => arrPendingTrack.push( objInstance.name ) );

objRegistryPending.register( 'Task', 'taskB', { name: 'pending' }, 'delete' );
objRegistryPending.remove( 'Task', 'taskB' );
objRegistryPending.fireEvents();

check( arrPendingTrack.length === 0, 'Deferred: remove wirft das ausstehende Event weg (kein feuern nach dem Entfernen)' );

// --- register, get, getAll, has (Map of Maps, Blueprint-Filter) ---

const objFirst  = { name: 'first' };
const objSecond = { name: 'second' };

objRegistry.register( 'User', Registry.BLUEPRINTID, { name: 'blueprint' } );
objRegistry.fireEvents();

check( objRegistry.getAll( 'User' ).size === 0 && objRegistry.get( 'User', 'new' ) !== undefined, 'getAll: Blueprint gefiltert, direkter Zugang über get bleibt' );

// --- Instanz-Events: onReady mit Id = Einmal-Zustellung mit Replay ---

const arrOnceBefore = [];

objRegistry.onReady( 'Role', 'roleA', ( objInstance ) => arrOnceBefore.push( objInstance.name ) );
objRegistry.register( 'Role', 'roleA', { name: 'alpha' } );
objRegistry.fireEvents();

check( arrOnceBefore.length === 1 && arrOnceBefore[ 0 ] === 'alpha', 'onReady mit Id: Listener vor der Registration feuert beim Flush' );

const arrOnceReplay = [];

objRegistry.onReady( 'Role', 'roleA', ( objInstance ) => arrOnceReplay.push( objInstance.name ) );

check( arrOnceReplay.length === 1 && arrOnceReplay[ 0 ] === 'alpha', 'onReady mit Id: Listener nach der Registration bekommt den Replay sofort' );

objRegistry.register( 'Role', 'roleB', { name: 'beta' } );
objRegistry.fireEvents();

check( arrOnceBefore.length === 1 && arrOnceReplay.length === 1, 'onReady mit Id: Once-Callback feuert genau einmal pro Event (fremde Id feuert nicht nach)' );

// --- Klassen-Events: onReady ohne Id = permanente Subscription mit vollem Replay ---

const arrClassEarly = [];

objRegistry.onReady( 'Weed', null, ( objInstance ) => arrClassEarly.push( objInstance.name ) );

objRegistry.register( 'Weed', 'weedA', { name: 'one' } );
objRegistry.register( 'Weed', 'weedB', { name: 'two' } );
objRegistry.register( 'Weed', Registry.BLUEPRINTID, { name: 'blueprint' } );
objRegistry.fireEvents();

check( arrClassEarly.length === 2 && arrClassEarly[ 0 ] === 'one' && arrClassEarly[ 1 ] === 'two', 'Klassenweit: einmal registriert feuert die Subscription pro neuer Instanz' );

const arrClassLate = [];

objRegistry.onReady( 'Weed', null, ( objInstance ) => arrClassLate.push( objInstance.name ) );

check( arrClassLate.length === 2 && arrClassLate[ 0 ] === 'one' && arrClassLate[ 1 ] === 'two', 'Klassenweit: Late-Listener bekommt den vollen Replay (Blueprint ausgespart)' );

objRegistry.register( 'Weed', 'weedC', { name: 'three' } );
objRegistry.fireEvents();

check( arrClassEarly.length === 3 && arrClassLate.length === 3, 'Klassenweit: Subscription bleibt für spätere Instanzen permanent' );

// --- loadall: Sammel-Kanal der Load-Batches ---

const objRegistryLoadAll = new Registry();
const arrLoadAllTrack    = [];

objRegistryLoadAll.onLoadAll( 'Task', ( mapInstances ) => arrLoadAllTrack.push( mapInstances ) );

objRegistryLoadAll.register( 'Task', 'taskA', { name: 'A' }, 'load' );
objRegistryLoadAll.register( 'Task', 'taskB', { name: 'B' }, 'load' );
objRegistryLoadAll.register( 'Sub', 'subA', { name: 'S' }, 'load' );
objRegistryLoadAll.fireEvents();

check( arrLoadAllTrack.length === 1 && arrLoadAllTrack[ 0 ].size === 2 && arrLoadAllTrack[ 0 ].get( 'taskA' ).name === 'A', 'loadall: Load-Batch feuert einmal mit der kompletten Instanz-Map der Klasse' );

const arrLoadAllLate = [];

objRegistryLoadAll.onLoadAll( 'Task', ( mapInstances ) => arrLoadAllLate.push( mapInstances ) );

check( arrLoadAllLate.length === 1 && arrLoadAllLate[ 0 ].size === 2, 'loadall: Late-Listener bekommt den Replay der Instanz-Map sofort' );

const intTrackBefore = arrLoadAllTrack.length;

objRegistryLoadAll.register( 'Task', 'taskC', { name: 'C' }, 'save' );
objRegistryLoadAll.fireEvents();

check( arrLoadAllTrack.length === intTrackBefore, 'loadall: Save-Batch feuert den Sammel-Kanal nicht' );

objRegistryLoadAll.remove( 'Task', 'taskB' );

const arrLoadAllSnapshot = [];

objRegistryLoadAll.onLoadAll( 'Task', ( mapInstances ) => arrLoadAllSnapshot.push( mapInstances ) );

check( arrLoadAllSnapshot.length === 1 && arrLoadAllSnapshot[ 0 ].size === 1 && ! arrLoadAllSnapshot[ 0 ].has( 'taskB' ), 'loadall: remove entfernt die Instanz aus dem Replay-Snapshot (kein Geist)' );

// --- App-Ready-Kanal: Speicherung, Dispatch mit App-Instanz, Replay ---

objRegistry.register( 'App', 'JPEnvelope_test', { name: 'app' } );
objRegistry.fireEvents();

const arrAppBefore = [];

objRegistry.onAppReady( ( app ) => arrAppBefore.push( app.name ) );
objRegistry.setAppReady();

const objApp = objRegistry.get( 'app' );

check( arrAppBefore.length === 1 && arrAppBefore[ 0 ] === 'app', 'AppReady: gespeicherter Callback feuert mit der App-Instanz' );

const arrAppAfter = [];

objRegistry.onAppReady( ( app ) => arrAppAfter.push( app.name ) );

check( arrAppAfter.length === 1 && arrAppAfter[ 0 ] === 'app' && objApp !== undefined, 'AppReady: Late-Listener bekommt den Replay' );

// --- remove: Entfernen ohne Geister-Instanzen und tote Replays ---

const objRegistryRemove = new Registry();
const arrRemoveTrack    = [];

objRegistryRemove.onReady( 'Item', null, ( objInstance ) => arrRemoveTrack.push( objInstance.name ) );

objRegistryRemove.register( 'Item', 'itemA', { name: 'A' } );
objRegistryRemove.register( 'Item', 'itemB', { name: 'B' } );
objRegistryRemove.fireEvents();

objRegistryRemove.remove( 'Item', 'itemA' );

check( objRegistryRemove.has( 'Item', 'itemA' ) === false && objRegistryRemove.has( 'Item', 'itemB' ) === true, 'remove: Instanz entfernt, Geschwister bleiben' );

arrRemoveTrack.length = 0;

objRegistryRemove.register( 'Item', 'itemC', { name: 'C' } );
objRegistryRemove.fireEvents();

check( arrRemoveTrack.length === 1 && arrRemoveTrack[ 0 ] === 'C', 'remove: entfernte Instanz feuert in der permanenten Subscription nicht nach' );

objRegistryRemove.remove( 'Item', 'itemC' );

const arrOnceAfter = [];

objRegistryRemove.onReady( 'Item', 'itemC', ( objInstance ) => arrOnceAfter.push( objInstance ) );

check( arrOnceAfter.length === 0, 'remove: Einmal-Replay der entfernten Id verfällt (kein Zombie-Dispatch)' );

const arrClassReplay = [];

objRegistryRemove.onReady( 'Item', null, ( objInstance ) => arrClassReplay.push( objInstance.name ) );

check( arrClassReplay.length === 1 && arrClassReplay[ 0 ] === 'B', 'remove: Klassenweiter Replay ohne die entfernten Instanzen' );

objRegistryRemove.remove( 'Item', 'itemB' );

check( objRegistryRemove.get( 'Item' ) === undefined, 'remove: letzter Eintrag entfernt, get liefert undefined' );

try {
  objRegistryRemove.remove( 'Item', 'itemB' );
} catch( removeError ) {
  check( false, 'remove: doppeltes Entfernen wirft' );
}

check( objRegistryRemove.has( 'Item', 'itemB' ) === false, 'remove: doppeltes Entfernen bleibt still' );

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
