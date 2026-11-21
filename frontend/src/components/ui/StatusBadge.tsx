import clsx from 'clsx'

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

interface BadgeStyle {
  label: string
  tone: Tone
}

const styles: Record<string, BadgeStyle> = {
  draft: { label: 'Draft', tone: 'neutral' },
  approved: { label: 'Approved', tone: 'success' },
  closed: { label: 'Closed', tone: 'info' },
  cancelled: { label: 'Cancelled', tone: 'danger' },
  posted: { label: 'Posted', tone: 'success' },
  reversed: { label: 'Reversed', tone: 'warning' },
  open: { label: 'Open', tone: 'info' },
  final: { label: 'Final', tone: 'success' },
  ok: { label: 'OK', tone: 'success' },
  mismatch: { label: 'Mismatch', tone: 'danger' },
  skipped: { label: 'Skipped', tone: 'neutral' },
}

const tones: Record<Tone, string> = {
  neutral: 'border-line bg-paper text-ink-muted',
  success: 'border-accent/30 bg-accent-soft text-accent',
  warning: 'border-amber-300/60 bg-amber-50 text-amber-800',
  danger: 'border-danger/30 bg-danger-soft text-danger',
  info: 'border-sky-300/60 bg-sky-50 text-sky-800',
}

interface StatusBadgeProps {
  status: string
  className?: string
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
  const style = styles[status.toLowerCase()] ?? {
    label: status,
    tone: 'neutral' as Tone,
  }

  return (
    <span
      className={clsx(
        'inline-flex items-center rounded border px-2 py-0.5 text-xs font-semibold',
        tones[style.tone],
        className,
      )}
    >
      {style.label}
    </span>
  )
}
