self.addEventListener( 'install', function( event ) {
  if( this.debug ) console.log( 'Service Worker Install' );
});

self.addEventListener( 'activate', function( event ) {
  if( this.debug ) console.log( 'Service Worker Activate' );
});

self.addEventListener( 'fetch', function( event ) {
  if( this.debug ) console.log( 'Service Worker Fetch' );
});