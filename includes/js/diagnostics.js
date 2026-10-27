/**
* This class is the frontend instance of the Diagnostics: It contains the
 * PHP unit testing results and has a form field validator
 * via the BaseObject. The class fills the container with the results
 * of the individual test suites.
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.14
 * @since     2026-10-26
 *
 * @example   const diagnostics = window[ appAlias ].registry.get( 'diagnostics' );
 *
 */
class Diagnostics extends BaseObject {

/**
 * This method is the constructor of the class: it wires only the
 * BaseObject base — the diagnostics data arrives as envelope (fillObject).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const diagnostics = new Diagnostics();
 *
 */
  constructor() {
    super();

    return;
  }

/**
* This method fills the diagnostics containers with the respective test suites.
 * These test suites contain the individual tests with their results.
 * The method will only be fully executed when the page is loaded
 * under the container “#diagnostics-container” exists.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   diagnostics.renderDiagnostics();
 *
 */
  renderDiagnostics() {
    if( document.querySelector( '#diagnostics-container' ) === null ) return;

    const container = document.querySelector( '#diagnostics-container' );

    for( let suiteAlias in this.diagnosticsData ) {
      const suite          = this.diagnosticsData[ suiteAlias ];
      const suiteContainer = document.createElement( 'div' );
      const headline       = document.createElement( 'p' );
      const table          = document.createElement( 'table' );

      headline.innerHTML   = 'Suite ' + suite.name;

      headline.addEventListener( 'click', ( event ) => {
        if( event.target.parentElement.classList.contains( 'diagnostics-suite-container-folded-in' ) ) {
          event.target.parentElement.classList.remove( 'diagnostics-suite-container-folded-in' );
        } else {
          event.target.parentElement.classList.add( 'diagnostics-suite-container-folded-in' );
        }

        return;
      } );

      suiteContainer.classList.add( 'diagnostics-suite-container' );
      table.classList.add( 'diagnostics-suite-table' );
      headline.classList.add( 'diagnostics-suite-headline' );

      if( suite.state.toLowerCase() !== 'ok' ) {
        headline.classList.add( 'diagnostics-suite-headline-fail' );
        headline.innerHTML += ' FAIL';
      } else {
        suiteContainer.classList.add( 'diagnostics-suite-container-folded-in' );
        headline.innerHTML += ' Ready';
      }

      for( let i = 0; i < suite.data.length; i++ ) {
        const tr            = document.createElement( 'tr' );
        const tdSub         = document.createElement( 'td' );
        const tdName        = document.createElement( 'td' );
        const tdState       = document.createElement( 'td' );
        const cssClassState = suite.data[ i ].state.toLowerCase() === 'ok' ? 'diagnostics-state-ready' : 'diagnostics-state-fail';

        tdSub.innerHTML     = suite.data[ i ].sub;
        tdName.innerHTML    = suite.data[ i ].name;
        tdState.innerHTML   = suite.data[ i ].state;

        tr.classList.add( 'diagnostics-suite-table-row', cssClassState );
        tdSub.classList.add( 'diagnostics-suite-table-cell-sub', cssClassState );
        tdName.classList.add( 'diagnostics-suite-table-cell-name', cssClassState );
        tdState.classList.add( 'diagnostics-suite-table-cell-state', cssClassState );

        tr.append( tdSub, tdName, tdState );
        table.append( tr );
      }

      suiteContainer.append( headline, table );
      container.append( suiteContainer );
    }

    return;
  }

}

window.Diagnostics = Diagnostics;
