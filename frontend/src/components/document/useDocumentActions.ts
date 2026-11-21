import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { api, ApiError, downloadFile, toErrorMessage } from '../../lib/api'
import type { BusinessError, DocumentAction } from '../document/DocumentShell'
import { useToast } from '../ui/toast-context'

interface UseDocumentActionsOptions {
  resource: string
  permissionPrefix: string
  id: number | null
  onChanged?: () => void
}

interface DocumentActionConfig {
  key: 'approve' | 'disapprove' | 'close' | 'reverse'
  label: string
  permission: string
  tone?: 'primary' | 'danger' | 'neutral'
  confirmMessage?: string
  allowedStatuses: string[]
}

const actionConfigs: Record<string, DocumentActionConfig> = {
  approve: {
    key: 'approve',
    label: 'Approve',
    permission: 'approve',
    tone: 'primary',
    confirmMessage: 'Setujui dokumen ini? Setelah approved, dokumen tidak dapat diubah.',
    allowedStatuses: ['draft'],
  },
  disapprove: {
    key: 'disapprove',
    label: 'Disapprove',
    permission: 'disapprove',
    tone: 'danger',
    confirmMessage: 'Batalkan persetujuan dokumen ini? Stok dan jurnal akan dibalik.',
    allowedStatuses: ['approved'],
  },
  close: {
    key: 'close',
    label: 'Close',
    permission: 'close',
    tone: 'neutral',
    confirmMessage: 'Tutup dokumen ini?',
    allowedStatuses: ['approved'],
  },
  reverse: {
    key: 'reverse',
    label: 'Reverse',
    permission: 'reverse',
    tone: 'danger',
    confirmMessage: 'Balik jurnal ini?',
    allowedStatuses: ['posted'],
  },
}

export function useDocumentActions({ resource, permissionPrefix, id, onChanged }: UseDocumentActionsOptions) {
  const toast = useToast()
  const queryClient = useQueryClient()
  const [businessError, setBusinessError] = useState<BusinessError | null>(null)
  const [busyAction, setBusyAction] = useState<string | null>(null)
  const [pendingAction, setPendingAction] = useState<DocumentAction | null>(null)

  const actionMutation = useMutation({
    mutationFn: (action: string) => api.post(`${resource}/${String(id)}/${action}`),
  })

  const printMutation = useMutation({
    mutationFn: () => downloadFile(`${resource}/${String(id)}/print`, `${resource}-${String(id)}.pdf`),
  })

  const runAction = useCallback(
    async (action: string): Promise<void> => {
      setBusyAction(action)
      setBusinessError(null)

      try {
        if (action === 'print') {
          await printMutation.mutateAsync()
        } else {
          await actionMutation.mutateAsync(action)
          await queryClient.invalidateQueries({ queryKey: ['document', resource] })
          await queryClient.invalidateQueries({ queryKey: ['documents', resource] })
          onChanged?.()
        }

        toast.push(action === 'print' ? 'Berkas diunduh.' : 'Aksi berhasil.', 'success')
      } catch (error) {
        if (error instanceof ApiError) {
          setBusinessError({ code: error.code, message: error.message, errors: error.errors })
        } else {
          setBusinessError({ code: 'ERROR', message: toErrorMessage(error), errors: {} })
        }

        toast.push(toErrorMessage(error), 'error')
      } finally {
        setBusyAction(null)
        setPendingAction(null)
      }
    },
    [actionMutation, printMutation, queryClient, resource, onChanged, toast],
  )

  const buildActions = useCallback(
    (
      status: string,
      options: { print?: boolean; closable?: boolean; extra?: DocumentAction[] } = {},
    ): DocumentAction[] => {
      const actions: DocumentAction[] = []

      for (const config of Object.values(actionConfigs)) {
        if (!config.allowedStatuses.includes(status)) {
          continue
        }

        if (config.key === 'close' && options.closable !== true) {
          continue
        }

        actions.push({
          key: config.key,
          label: config.label,
          permission: `${permissionPrefix}.${config.permission}`,
          tone: config.tone,
          confirmMessage: config.confirmMessage,
        })
      }

      if (options.print === true) {
        actions.push({
          key: 'print',
          label: 'Cetak PDF',
          permission: `${permissionPrefix}.print`,
          tone: 'neutral',
        })
      }

      return [...actions, ...(options.extra ?? [])]
    },
    [permissionPrefix],
  )

  return {
    businessError,
    setBusinessError,
    busyAction,
    pendingAction,
    setPendingAction,
    runAction,
    buildActions,
  }
}
