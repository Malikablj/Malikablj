/**
 * The pg driver for migration scripts, with the same type parsing as the API
 * (apps/api/src/db/pool.js): DATE stays a "YYYY-MM-DD" string, NUMERIC and BIGINT (e.g. count(*))
 * become JS numbers. Every migration module imports pg from here, so results never depend on
 * which other module happened to be loaded first.
 */
import pg from 'pg';

pg.types.setTypeParser(1082, (value) => value);
pg.types.setTypeParser(1700, (value) => (value === null ? null : Number(value)));
pg.types.setTypeParser(20, (value) => (value === null ? null : Number(value)));

export default pg;
