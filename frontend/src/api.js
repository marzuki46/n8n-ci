import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: { Accept: 'application/json' },
})

let csrfToken = null
let csrfPromise = null

function fetchCsrfToken() {
  if (csrfToken) return Promise.resolve(csrfToken)
  if (!csrfPromise) {
    csrfPromise = axios
      .get('/api/csrf', { withCredentials: true })
      .then((res) => {
        csrfToken = res.data?.data?.token || null
        return csrfToken
      })
      .finally(() => {
        csrfPromise = null
      })
  }
  return csrfPromise
}

api.interceptors.request.use(async (config) => {
  const method = (config.method || 'get').toLowerCase()
  const isStateChange = ['post', 'put', 'patch', 'delete'].includes(method)

  if (isStateChange && !config.headers['X-CSRF-TOKEN']) {
    const token = await fetchCsrfToken()
    if (token) config.headers['X-CSRF-TOKEN'] = token
  }
  return config
})

api.interceptors.response.use(
  (res) => res.data,
  async (err) => {
    const data = err.response?.data
    const message = data?.message || err.message || 'Terjadi kesalahan'

    if (err.response?.status === 401 && !location.hash.startsWith('#/login')) {
      location.hash = '#/login'
    }

    // Token CSRF kedaluwarsa/berubah → ambil ulang lalu coba sekali lagi.
    const isCsrfFailure =
      err.response?.status === 403 &&
      /csrf|not allowed|tidak diizinkan/i.test(message)

    if (isCsrfFailure && !err.config?._csrfRetried) {
      err.config._csrfRetried = true
      csrfToken = null
      const token = await fetchCsrfToken()
      if (token) err.config.headers['X-CSRF-TOKEN'] = token
      return api.request(err.config)
    }

    return Promise.reject(new Error(message))
  }
)

export default api
