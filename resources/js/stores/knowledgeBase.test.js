import { setActivePinia, createPinia } from 'pinia'
import { useKnowledgeBaseStore } from '@/stores/knowledgeBase'
import axios from 'axios'

describe('Knowledge Base Store', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        vi.clearAllMocks()
    })

    describe('initial state', () => {
        it('should have empty entries initially', () => {
            const store = useKnowledgeBaseStore()
            expect(store.entries).toEqual([])
        })

        it('should have null currentEntry initially', () => {
            const store = useKnowledgeBaseStore()
            expect(store.currentEntry).toBeNull()
        })

        it('should have empty categories initially', () => {
            const store = useKnowledgeBaseStore()
            expect(store.categories).toEqual([])
        })

        it('should not be loading initially', () => {
            const store = useKnowledgeBaseStore()
            expect(store.loading).toBe(false)
        })

        it('should have default pagination', () => {
            const store = useKnowledgeBaseStore()
            expect(store.pagination).toEqual({
                currentPage: 1,
                lastPage: 1,
                perPage: 20,
                total: 0
            })
        })

        it('should have empty filters', () => {
            const store = useKnowledgeBaseStore()
            expect(store.filters).toEqual({
                search: '',
                category: '',
                is_active: null
            })
        })
    })

    describe('getters', () => {
        it('should filter active entries', () => {
            const store = useKnowledgeBaseStore()
            store.entries = [
                { id: 1, title: 'Entry 1', is_active: true },
                { id: 2, title: 'Entry 2', is_active: false },
                { id: 3, title: 'Entry 3', is_active: true }
            ]

            expect(store.activeEntries).toHaveLength(2)
            expect(store.activeEntries.map(e => e.id)).toEqual([1, 3])
        })

        it('should filter inactive entries', () => {
            const store = useKnowledgeBaseStore()
            store.entries = [
                { id: 1, title: 'Entry 1', is_active: true },
                { id: 2, title: 'Entry 2', is_active: false },
                { id: 3, title: 'Entry 3', is_active: false }
            ]

            expect(store.inactiveEntries).toHaveLength(2)
            expect(store.inactiveEntries.map(e => e.id)).toEqual([2, 3])
        })
    })

    describe('fetchEntries', () => {
        it('should fetch entries and update state', async () => {
            const store = useKnowledgeBaseStore()
            const mockResponse = {
                data: {
                    data: [
                        { id: 1, title: 'FAQ', is_active: true },
                        { id: 2, title: 'Guide', is_active: true }
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 2
                }
            }
            axios.get.mockResolvedValueOnce(mockResponse)

            await store.fetchEntries()

            expect(store.entries).toHaveLength(2)
            expect(store.entries[0].title).toBe('FAQ')
            expect(store.pagination.total).toBe(2)
            expect(axios.get).toHaveBeenCalledWith('/api/knowledge-base', expect.any(Object))
        })

        it('should set loading state during fetch', async () => {
            const store = useKnowledgeBaseStore()
            axios.get.mockImplementation(() => new Promise(resolve => 
                setTimeout(() => resolve({ data: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 } }), 100)
            ))

            const fetchPromise = store.fetchEntries()
            expect(store.loading).toBe(true)

            await fetchPromise
            expect(store.loading).toBe(false)
        })

        it('should set error on fetch failure', async () => {
            const store = useKnowledgeBaseStore()
            axios.get.mockRejectedValueOnce({
                response: { data: { message: 'Server error' } }
            })

            await expect(store.fetchEntries()).rejects.toThrow()
            expect(store.error).toBe('Server error')
        })

        it('should apply filters when fetching', async () => {
            const store = useKnowledgeBaseStore()
            store.filters.search = 'test'
            store.filters.category = 'FAQ'
            axios.get.mockResolvedValueOnce({ data: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 } })

            await store.fetchEntries()

            expect(axios.get).toHaveBeenCalledWith('/api/knowledge-base', {
                params: expect.objectContaining({
                    search: 'test',
                    category: 'FAQ'
                })
            })
        })
    })

    describe('fetchCategories', () => {
        it('should fetch categories', async () => {
            const store = useKnowledgeBaseStore()
            axios.get.mockResolvedValueOnce({
                data: { data: ['FAQ', 'Tutorials', 'Support'] }
            })

            await store.fetchCategories()

            expect(store.categories).toEqual(['FAQ', 'Tutorials', 'Support'])
        })
    })

    describe('fetchEntry', () => {
        it('should fetch a single entry by id', async () => {
            const store = useKnowledgeBaseStore()
            const mockEntry = { id: 1, title: 'FAQ', content: 'Some content', is_active: true }
            axios.get.mockResolvedValueOnce({ data: { data: mockEntry } })

            const result = await store.fetchEntry(1)

            expect(store.currentEntry).toEqual(mockEntry)
            expect(result).toEqual(mockEntry)
            expect(axios.get).toHaveBeenCalledWith('/api/knowledge-base/1')
        })
    })

    describe('createEntry', () => {
        it('should create a new entry', async () => {
            const store = useKnowledgeBaseStore()
            const newEntry = { id: 1, title: 'New Entry', content: 'Content', is_active: true }
            axios.post.mockResolvedValueOnce({ data: { data: newEntry } })
            axios.get.mockResolvedValue({ data: { data: [newEntry], current_page: 1, last_page: 1, per_page: 20, total: 1 } })

            await store.createEntry({ title: 'New Entry', content: 'Content' })

            expect(axios.post).toHaveBeenCalledWith('/api/knowledge-base', expect.any(FormData), expect.any(Object))
        })
    })

    describe('deleteEntry', () => {
        it('should delete an entry and update state', async () => {
            const store = useKnowledgeBaseStore()
            store.entries = [
                { id: 1, title: 'Entry 1' },
                { id: 2, title: 'Entry 2' }
            ]
            axios.delete.mockResolvedValueOnce({})
            axios.get.mockResolvedValueOnce({ data: { data: [] } })

            await store.deleteEntry(1)

            expect(axios.delete).toHaveBeenCalledWith('/api/knowledge-base/1')
            expect(store.entries.find(e => e.id === 1)).toBeUndefined()
        })
    })

    describe('toggleStatus', () => {
        it('should toggle entry status', async () => {
            const store = useKnowledgeBaseStore()
            store.entries = [{ id: 1, title: 'Entry 1', is_active: true }]
            axios.post.mockResolvedValueOnce({ data: { data: { id: 1, title: 'Entry 1', is_active: false } } })

            await store.toggleStatus(1)

            expect(axios.post).toHaveBeenCalledWith('/api/knowledge-base/1/toggle')
            expect(store.entries[0].is_active).toBe(false)
        })
    })

    describe('filter management', () => {
        it('should set filters', () => {
            const store = useKnowledgeBaseStore()
            
            store.setFilters({ search: 'test', category: 'FAQ' })

            expect(store.filters.search).toBe('test')
            expect(store.filters.category).toBe('FAQ')
        })

        it('should reset filters', () => {
            const store = useKnowledgeBaseStore()
            store.filters = { search: 'test', category: 'FAQ', is_active: true }

            store.resetFilters()

            expect(store.filters).toEqual({
                search: '',
                category: '',
                is_active: null
            })
        })
    })
})
