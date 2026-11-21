const dateTimeFormatter = new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short',
  timeZone: 'Asia/Jakarta',
})

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeZone: 'Asia/Jakarta',
})

const currencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 2,
})

const quantityFormatter = new Intl.NumberFormat('id-ID', {
  maximumFractionDigits: 4,
})

const monthFormatter = new Intl.DateTimeFormat('id-ID', {
  month: 'short',
  year: '2-digit',
  timeZone: 'Asia/Jakarta',
})

export function formatDateTime(isoDate: string): string {
  const date = new Date(isoDate)

  if (Number.isNaN(date.getTime())) {
    return isoDate
  }

  return `${dateTimeFormatter.format(date)} WIB`
}

export function formatDate(isoDate: string): string {
  const date = new Date(`${isoDate}T00:00:00+07:00`)

  if (Number.isNaN(date.getTime())) {
    return isoDate
  }

  return dateFormatter.format(date)
}

export function formatCurrency(value: string | number): string {
  const amount = typeof value === 'number' ? value : Number.parseFloat(value)

  if (!Number.isFinite(amount)) {
    return String(value)
  }

  return currencyFormatter.format(amount)
}

export function formatQuantity(value: string | number): string {
  const amount = typeof value === 'number' ? value : Number.parseFloat(value)

  if (!Number.isFinite(amount)) {
    return String(value)
  }

  return quantityFormatter.format(amount)
}

export function formatMonth(key: string): string {
  const date = new Date(`${key}-01T00:00:00+07:00`)

  if (Number.isNaN(date.getTime())) {
    return key
  }

  return monthFormatter.format(date)
}
