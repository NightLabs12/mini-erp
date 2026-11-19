import { createBrowserRouter } from 'react-router-dom'
import { App } from '../App'
import { HealthPage } from '../pages/HealthPage'
import { NotFoundPage } from '../pages/NotFoundPage'

export const router = createBrowserRouter([
  {
    path: '/',
    element: <App />,
    children: [
      { index: true, element: <HealthPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
