// Date-only values are calendar dates: do not apply a timezone shift to DOB.
export function formatMalaysiaDate(value: string | null | undefined): string {
  if (!value) return 'Not available'
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return 'Not available'
  return `${match[3]}/${match[2]}/${match[1]}`
}

export function formatMalaysiaDateTime(value: string | null | undefined): string {
  if (!value) return 'Not available'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Not available'
  const parts = new Intl.DateTimeFormat('en-MY', {
    timeZone: 'Asia/Kuala_Lumpur', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(date)
  const part = (type: string) => parts.find((item) => item.type === type)!.value
  return `${part('day')}/${part('month')}/${part('year')}, ${part('hour')}:${part('minute')} MYT`
}
