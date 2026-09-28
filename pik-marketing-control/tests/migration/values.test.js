/** Source value parsing: the rules that keep the migration from guessing. */
import { describe, expect, it } from 'vitest';
import {
  normalizeKey,
  parseBoolean,
  parseDate,
  parseDateTime,
  parseEnum,
  parseNumber,
  parseTime,
} from '../../migration/scripts/lib/values.js';

describe('parseDate', () => {
  it('accepts real Excel dates, ISO text and Indonesian/English month names', () => {
    expect(parseDate(new Date(Date.UTC(2026, 0, 5))).value).toBe('2026-01-05');
    expect(parseDate('2026-01-05').value).toBe('2026-01-05');
    expect(parseDate('5 Januari 2026').value).toBe('2026-01-05');
    expect(parseDate('17-Agu-26').value).toBe('2026-08-17');
    expect(parseDate('3 Mar 2026').value).toBe('2026-03-03');
  });

  it('accepts D/M order only when unambiguous or declared', () => {
    expect(parseDate('15/03/2026').value).toBe('2026-03-15');
    expect(parseDate('03/15/2026').value).toBe('2026-03-15');
    expect(parseDate('03/04/2026').problem.type).toBe('AMBIGUOUS_DATE');
    expect(parseDate('03/04/2026', { format: 'DD/MM/YYYY' }).value).toBe('2026-04-03');
    expect(parseDate('03/04/2026', { format: 'MM/DD/YYYY' }).value).toBe('2026-03-04');
  });

  it('rejects impossible dates', () => {
    expect(parseDate('31/02/2026').problem.type).toBe('INVALID_DATE');
    expect(parseDate('bukan tanggal').problem.type).toBe('INVALID_DATE');
  });

  it('converts Excel serial numbers', () => {
    expect(parseDate(46027).value).toBe('2026-01-05');
  });
});

describe('parseNumber', () => {
  it('accepts plain numbers and requires a declared format for separators', () => {
    expect(parseNumber(1500).value).toBe(1500);
    expect(parseNumber('1500.5').value).toBe(1500.5);
    expect(parseNumber('12.5').value).toBe(12.5);
    expect(parseNumber('1.500').problem.type).toBe('AMBIGUOUS_NUMBER');
    expect(parseNumber('1,500').problem.type).toBe('AMBIGUOUS_NUMBER');
  });

  it('parses Indonesian and English notation when declared', () => {
    expect(parseNumber('1.500', { format: 'id' }).value).toBe(1500);
    expect(parseNumber('Rp 1.250.000,50', { format: 'id' }).value).toBe(1250000.5);
    expect(parseNumber('1,5', { format: 'id' }).value).toBe(1.5);
    expect(parseNumber('1,250,000.50', { format: 'en' }).value).toBe(1250000.5);
    expect(parseNumber('1.500', { format: 'en' }).value).toBe(1.5);
    expect(parseNumber('(2.000)', { format: 'id' }).value).toBe(-2000);
  });

  it('treats blanks as empty and text as invalid', () => {
    expect(parseNumber('  ').value).toBeNull();
    expect(parseNumber('abc').problem).toBeTruthy();
  });
});

describe('parseDateTime', () => {
  it('reads wall-clock values in the business timezone', () => {
    expect(parseDateTime(new Date(Date.UTC(2026, 2, 2, 10, 30)), { timeZone: 'Asia/Jakarta' }).value).toBe('2026-03-02T03:30:00.000Z');
    expect(parseDateTime('2026-03-02 10:30', { timeZone: 'Asia/Jakarta' }).value).toBe('2026-03-02T03:30:00.000Z');
    expect(parseDateTime('15/03/2026 08.00 WIB', { timeZone: 'Asia/Jakarta' }).value).toBe('2026-03-15T01:00:00.000Z');
    expect(parseDateTime('2026-03-02', { timeZone: 'Asia/Jakarta' }).value).toBe('2026-03-01T17:00:00.000Z');
  });
});

describe('parseTime / parseBoolean / parseEnum / normalizeKey', () => {
  it('parses times', () => {
    expect(parseTime('9:05').value).toBe('09:05:00');
    expect(parseTime('14.30').value).toBe('14:30:00');
    expect(parseTime(0.5).value).toBe('12:00:00');
    expect(parseTime('25:00').problem.type).toBe('INVALID_TIME');
  });

  it('parses Indonesian and English yes/no values', () => {
    expect(parseBoolean('Ya').value).toBe(true);
    expect(parseBoolean('tidak').value).toBe(false);
    expect(parseBoolean(1).value).toBe(true);
    expect(parseBoolean('mungkin').problem.type).toBe('INVALID_BOOLEAN');
  });

  it('maps labels through the value map and reports unknown labels', () => {
    const values = ['OPEN', 'ON_PROCESS', 'CLOSED'];
    expect(parseEnum('On Process', { values }).value).toBe('ON_PROCESS');
    expect(parseEnum('  proses ', { values, valueMap: { Proses: 'ON_PROCESS' } }).value).toBe('ON_PROCESS');
    expect(parseEnum('Selesai', { values }).problem.type).toBe('UNMAPPED_VALUE');
    expect(parseEnum('', { values }).value).toBeNull();
  });

  it('normalizes names the same way as the database normalize_key()', () => {
    expect(normalizeKey('  PT. Maju   Jaya, Tbk ')).toBe('pt maju jaya tbk');
    expect(normalizeKey('---')).toBeNull();
  });
});
