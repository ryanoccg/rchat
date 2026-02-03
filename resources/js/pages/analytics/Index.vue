<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useAnalyticsStore } from '../../stores/analytics'
import { useToast } from 'primevue/usetoast'
import Card from 'primevue/card'
import Select from 'primevue/select'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Chart from 'primevue/chart'
import ProgressBar from 'primevue/progressbar'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'

const store = useAnalyticsStore()
const toast = useToast()

const periodOptions = [
  { label: 'Last 7 Days', value: '7' },
  { label: 'Last 30 Days', value: '30' },
  { label: 'Last 90 Days', value: '90' },
  { label: 'Last Year', value: '365' },
]

const selectedPeriod = ref('30')

// Chart configurations
const conversationChartData = computed(() => {
  if (!store.conversationTrends.length) return null
  return {
    labels: store.conversationTrends.map(t => t.date),
    datasets: [
      {
        label: 'Total',
        data: store.conversationTrends.map(t => t.total),
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        fill: true,
        tension: 0.4,
      },
      {
        label: 'Resolved',
        data: store.conversationTrends.map(t => t.resolved),
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34, 197, 94, 0.1)',
        fill: true,
        tension: 0.4,
      },
      {
        label: 'AI Handled',
        data: store.conversationTrends.map(t => t.ai_handled),
        borderColor: '#a855f7',
        backgroundColor: 'rgba(168, 85, 247, 0.1)',
        fill: true,
        tension: 0.4,
      },
    ],
  }
})

const sentimentChartData = computed(() => {
  if (!store.sentimentData?.distribution?.length) return null
  const colors = {
    positive: '#22c55e',
    neutral: '#6b7280',
    negative: '#ef4444',
  }
  return {
    labels: store.sentimentData.distribution.map(s => s.sentiment),
    datasets: [
      {
        data: store.sentimentData.distribution.map(s => s.count),
        backgroundColor: store.sentimentData.distribution.map(s => colors[s.sentiment] || '#6b7280'),
      },
    ],
  }
})

const satisfactionChartData = computed(() => {
  if (!store.satisfactionData?.distribution?.length) return null
  return {
    labels: store.satisfactionData.distribution.map(r => `${r.rating} Star${r.rating > 1 ? 's' : ''}`),
    datasets: [
      {
        label: 'Ratings',
        data: store.satisfactionData.distribution.map(r => r.count),
        backgroundColor: ['#ef4444', '#f97316', '#eab308', '#84cc16', '#22c55e'],
      },
    ],
  }
})

const hourlyChartData = computed(() => {
  if (!store.hourlyDistribution.length) return null
  return {
    labels: store.hourlyDistribution.map(h => h.label),
    datasets: [
      {
        label: 'Conversations',
        data: store.hourlyDistribution.map(h => h.count),
        backgroundColor: '#3b82f6',
      },
    ],
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
    },
  },
}

const barChartOptions = {
  ...chartOptions,
  scales: {
    y: {
      beginAtZero: true,
    },
  },
}

onMounted(async () => {
  await loadData()
})

async function loadData() {
  try {
    await store.fetchAllData(selectedPeriod.value)
  } catch (e) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Failed to load analytics data',
      life: 5000,
    })
  }
}

async function exportData(format) {
  try {
    await store.exportData(format)
    toast.add({
      severity: 'success',
      summary: 'Exported',
      detail: `Analytics data exported as ${format.toUpperCase()}`,
      life: 3000,
    })
  } catch (e) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Failed to export data',
      life: 5000,
    })
  }
}

function getChangeClass(change) {
  if (change > 0) return 'text-green-500'
  if (change < 0) return 'text-red-500'
  return 'text-surface-500'
}

function getChangeIcon(change) {
  if (change > 0) return 'pi pi-arrow-up'
  if (change < 0) return 'pi pi-arrow-down'
  return 'pi pi-minus'
}

function getPlatformIcon(slug) {
  const icons = {
    facebook: 'pi-facebook',
    whatsapp: 'pi-whatsapp',
    telegram: 'pi-telegram',
    line: 'pi-comments',
  }
  return icons[slug] || 'pi-comment'
}

watch(selectedPeriod, () => {
  loadData()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-100">Analytics</h1>
        <p class="text-surface-500 mt-1">Monitor your customer service performance and trends</p>
      </div>
      <div class="flex items-center gap-3">
        <Select
          v-model="selectedPeriod"
          :options="periodOptions"
          optionLabel="label"
          optionValue="value"
          class="w-40"
        />
        <Button
          icon="pi pi-download"
          label="Export CSV"
          severity="secondary"
          :loading="store.exporting"
          @click="exportData('csv')"
        />
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="store.loading" class="space-y-6">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card v-for="i in 4" :key="i">
          <template #content>
            <Skeleton height="80px" />
          </template>
        </Card>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card v-for="i in 2" :key="i">
          <template #content>
            <Skeleton height="300px" />
          </template>
        </Card>
      </div>
    </div>

    <!-- Content -->
    <div v-else class="space-y-6">
      <!-- Overview Stats -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <template #content>
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm text-surface-500 dark:text-surface-400">Conversations</p>
                <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ store.overview?.total_conversations || 0 }}</p>
                <p :class="getChangeClass(store.overview?.conversation_change)" class="text-sm mt-1">
                  <i :class="getChangeIcon(store.overview?.conversation_change)" class="mr-1"></i>
                  {{ Math.abs(store.overview?.conversation_change || 0) }}% vs prev
                </p>
              </div>
              <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                <i class="pi pi-comments text-blue-500 text-xl"></i>
              </div>
            </div>
          </template>
        </Card>

        <Card>
          <template #content>
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm text-surface-500 dark:text-surface-400">AI Handle Rate</p>
                <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ store.overview?.ai_handle_rate || 0 }}%</p>
                <p class="text-sm text-surface-500 mt-1">Automated responses</p>
              </div>
              <div class="w-12 h-12 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                <i class="pi pi-bolt text-purple-500 text-xl"></i>
              </div>
            </div>
          </template>
        </Card>

        <Card>
          <template #content>
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm text-surface-500 dark:text-surface-400">Avg Response Time</p>
                <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ store.overview?.avg_response_time || 0 }}m</p>
                <p class="text-sm text-surface-500 mt-1">Minutes to first response</p>
              </div>
              <div class="w-12 h-12 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                <i class="pi pi-clock text-yellow-500 text-xl"></i>
              </div>
            </div>
          </template>
        </Card>

        <Card>
          <template #content>
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm text-surface-500 dark:text-surface-400">Resolution Rate</p>
                <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ store.overview?.resolution_rate || 0 }}%</p>
                <p class="text-sm text-surface-500 mt-1">Conversations resolved</p>
              </div>
              <div class="w-12 h-12 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <i class="pi pi-check-circle text-green-500 text-xl"></i>
              </div>
            </div>
          </template>
        </Card>
      </div>

      <!-- Charts Row -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Conversation Trends -->
        <Card>
          <template #title>
            <span class="text-lg font-semibold">Conversation Trends</span>
          </template>
          <template #content>
            <div class="h-80">
              <Chart 
                v-if="conversationChartData" 
                type="line" 
                :data="conversationChartData" 
                :options="chartOptions"
                class="h-full"
              />
              <div v-else class="h-full flex items-center justify-center text-surface-500">
                No data available
              </div>
            </div>
          </template>
        </Card>

        <!-- Hourly Distribution -->
        <Card>
          <template #title>
            <span class="text-lg font-semibold">Hourly Activity</span>
          </template>
          <template #content>
            <div class="h-80">
              <Chart 
                v-if="hourlyChartData" 
                type="bar" 
                :data="hourlyChartData" 
                :options="barChartOptions"
                class="h-full"
              />
              <div v-else class="h-full flex items-center justify-center text-surface-500">
                No data available
              </div>
            </div>
          </template>
        </Card>
      </div>

      <!-- Sentiment & Satisfaction -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sentiment Distribution -->
        <Card>
          <template #title>
            <span class="text-lg font-semibold">Sentiment Analysis</span>
          </template>
          <template #content>
            <div class="h-64">
              <Chart 
                v-if="sentimentChartData" 
                type="doughnut" 
                :data="sentimentChartData" 
                :options="chartOptions"
                class="h-full"
              />
              <div v-else class="h-full flex items-center justify-center text-surface-500">
                No data available
              </div>
            </div>
            <div v-if="store.sentimentData" class="mt-4 text-center">
              <p class="text-sm text-surface-500">Overall Score</p>
              <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ store.sentimentData.overall_score }}</p>
            </div>
          </template>
        </Card>

        <!-- Satisfaction Ratings -->
        <Card>
          <template #title>
            <span class="text-lg font-semibold">Customer Satisfaction</span>
          </template>
          <template #content>
            <div class="h-64">
              <Chart 
                v-if="satisfactionChartData" 
                type="bar" 
                :data="satisfactionChartData" 
                :options="barChartOptions"
                class="h-full"
              />
              <div v-else class="h-full flex items-center justify-center text-surface-500">
                No data available
              </div>
            </div>
            <div v-if="store.satisfactionData" class="mt-4 grid grid-cols-2 gap-4 text-center">
              <div>
                <p class="text-sm text-surface-500">Avg Rating</p>
                <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ store.satisfactionData.avg_rating }}/5</p>
              </div>
              <div>
                <p class="text-sm text-surface-500">NPS</p>
                <p class="text-xl font-bold" :class="store.satisfactionData.nps >= 0 ? 'text-green-500' : 'text-red-500'">
                  {{ store.satisfactionData.nps }}
                </p>
              </div>
            </div>
          </template>
        </Card>

        <!-- Platform Performance -->
        <Card>
          <template #title>
            <span class="text-lg font-semibold">Platform Performance</span>
          </template>
          <template #content>
            <div v-if="store.platformPerformance.length" class="space-y-4">
              <div
                v-for="platform in store.platformPerformance"
                :key="platform.platform_slug"
                class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-700 rounded-lg"
              >
                <div class="flex items-center gap-3">
                  <i :class="['pi', getPlatformIcon(platform.platform_slug), 'text-xl']"></i>
                  <span class="font-medium text-surface-900 dark:text-surface-100">{{ platform.platform }}</span>
                </div>
                <div class="text-right">
                  <p class="font-bold text-surface-900 dark:text-surface-100">{{ platform.conversations }}</p>
                  <p class="text-xs text-surface-500">{{ platform.resolved }} resolved</p>
                </div>
              </div>
            </div>
            <div v-else class="h-64 flex items-center justify-center text-surface-500">
              No platform data
            </div>
          </template>
        </Card>
      </div>

      <!-- Agent Performance Table -->
      <Card>
        <template #title>
          <span class="text-lg font-semibold">Agent Performance</span>
        </template>
        <template #content>
          <DataTable
            :value="store.agentPerformance"
            stripedRows
            class="p-datatable-sm"
          >
            <template #empty>
              <div class="text-center py-8 text-surface-500">
                No agent data available
              </div>
            </template>

            <Column field="agent_name" header="Agent" style="min-width: 150px">
              <template #body="{ data }">
                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center">
                    <i class="pi pi-user text-sm"></i>
                  </div>
                  <span class="font-medium text-surface-900 dark:text-surface-100">{{ data.agent_name }}</span>
                </div>
              </template>
            </Column>

            <Column field="conversations" header="Conversations" style="width: 150px">
              <template #body="{ data }">
                <span class="font-bold text-surface-900 dark:text-surface-100">{{ data.conversations }}</span>
              </template>
            </Column>

            <Column field="resolved" header="Resolved" style="width: 150px">
              <template #body="{ data }">
                <div class="flex items-center gap-2">
                  <span class="text-surface-900 dark:text-surface-100">{{ data.resolved }}</span>
                  <Tag 
                    v-if="data.conversations > 0"
                    :value="`${Math.round((data.resolved / data.conversations) * 100)}%`"
                    :severity="(data.resolved / data.conversations) >= 0.8 ? 'success' : 'warn'"
                  />
                </div>
              </template>
            </Column>

            <Column field="avg_confidence" header="Avg Confidence" style="width: 150px">
              <template #body="{ data }">
                <ProgressBar 
                  :value="Math.round((data.avg_confidence || 0) * 100)" 
                  :showValue="true"
                  style="height: 20px"
                />
              </template>
            </Column>
          </DataTable>
        </template>
      </Card>
    </div>
  </div>
</template>
