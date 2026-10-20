import { describe, expect, it } from 'vitest'
import { malaysiaToday, suggestNricDateOfBirth } from '@/services/identityDateOfBirth'

describe('NRIC date of birth suggestions', () => {
  it.each([
    ['900101-10-1234', '1990-01-01'],
    ['900101101234', '1990-01-01'],
    ['000229-10-1234', '2000-02-29'],
    ['200101-10-1234', '2020-01-01'],
    ['261019-10-1234', '2026-10-19'],
    ['270101-10-1234', '1927-01-01'],
  ])('suggests a calendar date for %s', (identity, expected) => {
    expect(suggestNricDateOfBirth(identity, '2026-10-20')).toBe(expected)
  })

  it.each(['', 'A1234567', '900101', '9001011012345', '900101-101234', '900230-10-1234', '900000-10-1234', '261020-10-1234', '261021-10-1234'])('leaves DOB for manual entry for %s', (identity) => {
    expect(suggestNricDateOfBirth(identity, '2026-10-20')).toBeNull()
  })

  it('uses the Kuala Lumpur date at the UTC day boundary', () => {
    expect(malaysiaToday(new Date('2026-10-19T17:00:00Z'))).toBe('2026-10-20')
  })
})
