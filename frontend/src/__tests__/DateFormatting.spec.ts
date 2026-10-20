import { describe, expect, it } from 'vitest'
import { formatMalaysiaDate, formatMalaysiaDateTime } from '@/services/dateFormatting'

describe('Malaysia date display', () => {
  it('displays calendar dates day first without shifting the date', () => {
    expect(formatMalaysiaDate('1990-01-02')).toBe('02/01/1990')
    expect(formatMalaysiaDate('2000-02-29')).toBe('29/02/2000')
  })

  it('converts UTC timestamps to Malaysia time including a day boundary', () => {
    expect(formatMalaysiaDateTime('2026-10-19T17:05:00Z')).toBe('20/10/2026, 01:05 MYT')
  })

  it('handles year boundaries and explicit timezone offsets', () => {
    expect(formatMalaysiaDateTime('2026-12-31T20:15:00Z')).toBe('01/01/2027, 04:15 MYT')
    expect(formatMalaysiaDateTime('2026-10-20T09:30:00+08:00')).toBe('20/10/2026, 09:30 MYT')
  })

  it('shows a fallback for missing or malformed dates', () => {
    expect(formatMalaysiaDate(null)).toBe('Not available')
    expect(formatMalaysiaDate('invalid')).toBe('Not available')
    expect(formatMalaysiaDateTime(undefined)).toBe('Not available')
    expect(formatMalaysiaDateTime('invalid')).toBe('Not available')
  })
})
