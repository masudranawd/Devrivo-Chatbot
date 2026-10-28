/**
 * Fails when a build file exceeds its size budget (minified, not gzipped).
 */
import { statSync } from 'node:fs';

const LIMITS = {
	'build/widget-loader.js': 15 * 1024,
};

let failed = false;

for ( const [ file, limit ] of Object.entries( LIMITS ) ) {
	let size;
	try {
		size = statSync( file ).size;
	} catch {
		console.error( `✗ ${ file } is missing. Run "npm run build" first.` );
		failed = true;
		continue;
	}
	const ok = size <= limit;
	console.log( `${ ok ? '✓' : '✗' } ${ file }: ${ ( size / 1024 ).toFixed( 1 ) } KB (limit ${ limit / 1024 } KB)` );
	failed ||= ! ok;
}

process.exit( failed ? 1 : 0 );
