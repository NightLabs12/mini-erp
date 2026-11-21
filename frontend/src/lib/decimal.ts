/**
 * Aritmetika desimal berbasis BigInt agar hasil frontend sama dengan backend
 * (bcmath). Nilai selalu string; float tidak dipakai untuk uang.
 *
 * - `decMul` memotong (truncate) pada skala yang diminta, seperti bcmul.
 * - `decDiv` dan `decRound` memakai pembulatan HALF_UP menjauhi nol.
 */

const PATTERN = /^-?\d+(\.\d+)?$/
const WORK_SCALE = 12

interface Parsed {
  negative: boolean
  digits: bigint
  scale: number
}

function parse(value: string | number): Parsed {
  const text = typeof value === 'number' ? String(value) : value.trim()

  if (text === '' || PATTERN.test(text) !== true) {
    throw new Error(`Nilai desimal tidak valid: ${String(value)}`)
  }

  const negative = text.startsWith('-')
  const unsigned = negative ? text.slice(1) : text
  const [whole = '0', fraction = ''] = unsigned.split('.')

  return {
    negative,
    digits: BigInt(`${whole}${fraction}` || '0'),
    scale: fraction.length,
  }
}

function pow10(exponent: number): bigint {
  return 10n ** BigInt(exponent)
}

function format(digits: bigint, scale: number, negative: boolean): string {
  const absolute = digits < 0n ? -digits : digits
  const padded = absolute.toString().padStart(scale + 1, '0')
  const whole = scale === 0 ? padded : padded.slice(0, padded.length - scale)
  const fraction = scale === 0 ? '' : `.${padded.slice(padded.length - scale)}`
  const isZero = absolute === 0n

  return negative && !isZero ? `-${whole}${fraction}` : `${whole}${fraction}`
}

function toWork(value: string | number): bigint {
  const parsed = parse(value)
  const shift = WORK_SCALE - parsed.scale
  const scaled = shift >= 0 ? parsed.digits * pow10(shift) : parsed.digits / pow10(-shift)

  return parsed.negative ? -scaled : scaled
}

/** Pembulatan HALF_UP menjauhi nol dari bilangan berskala WORK_SCALE. */
function fromWork(work: bigint, scale: number): string {
  const negative = work < 0n
  const absolute = negative ? -work : work

  if (scale >= WORK_SCALE) {
    return format(absolute * pow10(scale - WORK_SCALE), scale, negative)
  }

  const divisor = pow10(WORK_SCALE - scale)
  const quotient = absolute / divisor
  const remainder = absolute % divisor
  const rounded = remainder * 2n >= divisor ? quotient + 1n : quotient

  return format(rounded, scale, negative)
}

export function decRound(value: string | number, scale: number): string {
  return fromWork(toWork(value), scale)
}

export function decAdd(a: string | number, b: string | number, scale = 4): string {
  return fromWork(toWork(a) + toWork(b), scale)
}

export function decSub(a: string | number, b: string | number, scale = 4): string {
  return fromWork(toWork(a) - toWork(b), scale)
}

/** Perkalian dengan pemotongan pada skala, meniru bcmul. */
export function decMul(a: string | number, b: string | number, scale = 4): string {
  const left = parse(a)
  const right = parse(b)
  const negative = left.negative !== right.negative
  const product = left.digits * right.digits
  const productScale = left.scale + right.scale

  if (productScale > scale) {
    return format(product / pow10(productScale - scale), scale, negative)
  }

  return format(product * pow10(scale - productScale), scale, negative)
}

/** Pembagian dengan pembulatan HALF_UP pada skala. */
export function decDiv(a: string | number, b: string | number, scale = 4): string {
  const left = parse(a)
  const right = parse(b)

  if (right.digits === 0n) {
    throw new Error('Pembagian dengan nol tidak diperbolehkan.')
  }

  const shift = right.scale - left.scale + scale
  let numerator = left.digits
  let denominator = right.digits

  if (shift >= 0) {
    numerator *= pow10(shift)
  } else {
    denominator *= pow10(-shift)
  }

  const quotient = numerator / denominator
  const remainder = numerator % denominator
  const rounded = remainder * 2n >= denominator ? quotient + 1n : quotient

  return format(rounded, scale, left.negative !== right.negative)
}

export function decCompare(a: string | number, b: string | number): number {
  const left = toWork(a)
  const right = toWork(b)

  return left === right ? 0 : left > right ? 1 : -1
}

export function decIsZero(value: string | number): boolean {
  return decCompare(value, '0') === 0
}

export function decAbs(value: string | number, scale = 4): string {
  const result = decRound(value, scale)

  return result.startsWith('-') ? result.slice(1) : result
}

export function decSum(values: (string | number)[], scale = 2): string {
  return values.reduce<string>((total, value) => decAdd(total, value, scale), decRound('0', scale))
}
