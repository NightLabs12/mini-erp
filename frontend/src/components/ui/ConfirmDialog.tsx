import { t } from '../../lib/i18n'
import { Modal } from './Modal'

interface ConfirmDialogProps {
  open: boolean
  title: string
  description?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
  busy?: boolean
  onConfirm: () => void
  onCancel: () => void
}

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = t('common.confirm'),
  cancelLabel = t('common.cancel'),
  danger = false,
  busy = false,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  return (
    <Modal
      open={open}
      title={title}
      description={description}
      onClose={onCancel}
      size="sm"
      footer={
        <>
          <button
            type="button"
            onClick={onCancel}
            disabled={busy}
            className="inline-flex min-h-10 items-center rounded-md border border-line bg-surface px-3 text-sm font-medium hover:border-ink-muted disabled:opacity-60"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={busy}
            className={[
              'inline-flex min-h-10 items-center rounded-md px-3 text-sm font-semibold text-white disabled:opacity-60',
              danger ? 'bg-danger hover:bg-danger/90' : 'bg-accent hover:bg-accent/90',
            ].join(' ')}
          >
            {busy ? t('common.loading') : confirmLabel}
          </button>
        </>
      }
    />
  )
}
