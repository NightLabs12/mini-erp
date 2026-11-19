const messages = {
  'app.name': 'Mini ERP',
  'app.company': 'PT Contoh Niaga Makmur',
  'app.tagline': 'Perdagangan dan manufaktur kaos',
  'nav.status': 'Status sistem',
  'footer.phase': 'Fase 0: fondasi aplikasi. Modul master data, transaksi, dan laporan menyusul.',
  'health.title': 'Status sistem',
  'health.lead': 'Memeriksa koneksi antara antarmuka, API, dan database.',
  'health.loading': 'Memeriksa status layanan...',
  'health.retry': 'Periksa ulang',
  'health.checking': 'Memeriksa...',
  'health.api': 'API',
  'health.database': 'Database',
  'health.version': 'Versi',
  'health.environment': 'Lingkungan',
  'health.serverTime': 'Waktu server',
  'health.ok': 'OK',
  'health.degraded': 'Menurun',
  'health.errorTitle': 'API tidak dapat dihubungi',
  'health.errorHint': 'Pastikan backend berjalan, lalu periksa ulang.',
  'notFound.title': 'Halaman tidak ditemukan',
  'notFound.lead': 'Alamat yang Anda buka tidak tersedia.',
  'notFound.back': 'Kembali ke status sistem',
} as const

export type MessageKey = keyof typeof messages

export function t(key: MessageKey): string {
  return messages[key]
}
