// Use the community's calendar date, independently of the browser's timezone.
export function malaysiaToday(now = new Date()): string {
  const parts = new Intl.DateTimeFormat('en', {
    timeZone: 'Asia/Kuala_Lumpur', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(now)
  const part = (type: string) => parts.find((item) => item.type === type)!.value
  return `${part('year')}-${part('month')}-${part('day')}`
}

export function suggestNricDateOfBirth(identity: string, today = malaysiaToday()): string | null {
  const value = identity.trim()
  if (!/^(?:\d{12}|\d{6}-\d{2}-\d{4})$/.test(value)) return null
  const digits = value.replace(/-/g, '')
  const shortYear = Number(digits.slice(0, 2))
  const month = Number(digits.slice(2, 4))
  const day = Number(digits.slice(4, 6))
  const currentYear = Number(today.slice(0, 4))
  // YY does not identify a century. Suggest the latest matching year at or before
  // this year; the user must check it and can correct the century before registering.
  let year = Math.floor(currentYear / 100) * 100 + shortYear
  if (year > currentYear) year -= 100
  const parsed = new Date(Date.UTC(year, month - 1, day))
  if (parsed.getUTCFullYear() !== year || parsed.getUTCMonth() !== month - 1 || parsed.getUTCDate() !== day) return null
  const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
  // Do not guess a different century to turn a future-looking date into a DOB.
  return date < today ? date : null
}
