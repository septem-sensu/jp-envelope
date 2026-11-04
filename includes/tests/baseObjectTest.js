/**
 * This suite is the regression test for the client side reference
 * round trip of BaseObject: toData() serializes the instance back
 * into the data payload of the save envelope — declared builtin
 * fields travel flat, resolved reference instances return as the
 * mirrored collector descriptors (propertyType, property, class,
 * value) with their id respectively their element id list, empty
 * object references travel as null (clearing contract), empty
 * collections as empty list and undefined values stay out. Broken
 * collection entries without an id stay out of the id list.
 * The instance is built past the constructor (Object.create) — the
 * suite tests the pure serialization without DOM/loader dependency.
 * It loads baseObject.js over new Function() (the class file is
 * browser style without module export) and runs in node — same
 * pattern as registryTest.js: OK line per check, exit code 1 on the
 * first error.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.12
 * @since     2026-11-04
 *
 * @example   node includes/tests/baseObjectTest.js
 *
 */

'use strict';

const objFileSystem       = require( 'fs' );
const strBaseObjectSource = objFileSystem.readFileSync( __dirname + '/../js/baseObject.js', 'utf8' );
const BaseObject          = new Function( strBaseObjectSource + '\nreturn BaseObject;' )();

/**
 * This function is the regression check helper of the suite: a passing
 * condition prints the OK line, a failing condition prints the
 * FEHLER line and stops the suite with exit code 1.
 *
 * @param     {boolean}    boolCondition   The condition to check
 * @param     {string}     strMessage      The check description printed in both cases
 * @return    {void}
 *
 * @example   check( objData.demoTwo.value === 'demo-2', 'toData: Deskriptor' );
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

// --- toData: deklarierte Feldwerte und Referenz-Deskriptoren ---

const objInstance = Object.create( BaseObject.prototype );

objInstance.fields = {
  id:       { nameDe: 'ID', type: 'text' },
  name:     { nameDe: 'Name', type: 'text' },
  demoTwo:  { nameDe: 'Demo 2', class: 'DemoTwo' },
  users:    { nameDe: 'Benutzer', class: 'User', isCollection: true }
};

objInstance.id      = 'demo-1';
objInstance.name    = 'Test';
objInstance.demoTwo = { id: 'demo-2', name: 'Sub' };
objInstance.users   = [ { id: 'u-1' }, { id: 'u-2' } ];

const objData = objInstance.toData();

check( objData.id === 'demo-1' && objData.name === 'Test', 'toData: deklarierte Builtin-Felder reisen flach' );

check( objData.demoTwo.propertyType === 'object' && objData.demoTwo.property === 'demoTwo' && objData.demoTwo.class === 'DemoTwo' && objData.demoTwo.value === 'demo-2', 'toData: die Objekt-Referenz reist als Kollektor-Deskriptor mit der id' );

check( objData.users.propertyType === 'collection' && objData.users.property === 'users' && objData.users.class === 'User' && objData.users.value.length === 2 && objData.users.value[ 0 ] === 'u-1' && objData.users.value[ 1 ] === 'u-2', 'toData: die Collection-Referenz reist als Kollektor-Deskriptor mit der id-Liste' );

// --- toData: leere und fehlende Werte ---

objInstance.demoTwo = null;
objInstance.users   = [];
objInstance.name    = undefined;

const objEmptyData = objInstance.toData();

check( objEmptyData.demoTwo.value === null, 'toData: eine leere Objekt-Referenz reist als null (Leeren-Vertrag)' );
check( objEmptyData.users.value.length === 0, 'toData: eine leere Collection-Referenz reist als leere id-Liste' );
check( !( 'name' in objEmptyData ), 'toData: ein undefined-Wert bleibt außen vor (kein Merge)' );

// --- toData: kaputte Collection-Elemente ---

objInstance.users = [ { id: 'u-1' }, null, {} ];

const objBrokenData = objInstance.toData();

check( objBrokenData.users.value.length === 1 && objBrokenData.users.value[ 0 ] === 'u-1', 'toData: kaputte Collection-Elemente ohne id bleiben aus der id-Liste aus' );

console.log( 'Alle Tests bestanden.' );

// EOF
