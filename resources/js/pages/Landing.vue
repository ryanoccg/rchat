<template>
  <div class="ch-landing" :class="{ 'ch-dark': isDark }">
    <!-- Ambient background grain -->
    <div class="ch-grain"></div>

    <!-- ===== NAVIGATION ===== -->
    <nav class="ch-nav" :class="{ 'ch-nav--scrolled': scrolled }">
      <div class="ch-container ch-nav__inner">
        <a href="/" class="ch-nav__brand">
          <div class="ch-nav__logo">
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect width="32" height="32" rx="8" fill="currentColor" class="text-emerald-500"/>
              <path d="M8 12C8 10.8954 8.89543 10 10 10H22C23.1046 10 24 10.8954 24 12V18C24 19.1046 23.1046 20 22 20H18L14 24V20H10C8.89543 20 8 19.1046 8 18V12Z" fill="white"/>
              <circle cx="13" cy="15" r="1.5" fill="currentColor" class="text-emerald-500"/>
              <circle cx="19" cy="15" r="1.5" fill="currentColor" class="text-emerald-500"/>
            </svg>
          </div>
          <span class="ch-nav__wordmark">RChat</span>
        </a>

        <div class="ch-nav__links">
          <a href="#features" class="ch-nav__link">Features</a>
          <a href="#platforms" class="ch-nav__link">Platforms</a>
          <a href="#ai" class="ch-nav__link">AI Engine</a>
          <a href="#testimonials" class="ch-nav__link">Testimonials</a>
        </div>

        <div class="ch-nav__actions">
          <button @click="toggleTheme" class="ch-btn-icon" :aria-label="isDark ? 'Light mode' : 'Dark mode'">
            <i :class="isDark ? 'pi pi-sun' : 'pi pi-moon'"></i>
          </button>
          <template v-if="isLoggedIn">
            <a href="/dashboard">
              <Button label="Dashboard" icon="pi pi-th-large" outlined size="small" />
            </a>
          </template>
          <template v-else>
            <a href="/login" class="ch-nav__signin">Sign In</a>
            <a href="/register">
              <Button label="Get Started" size="small" class="ch-btn-accent" />
            </a>
          </template>
        </div>

        <!-- Mobile menu button -->
        <button class="ch-nav__mobile-toggle" @click="mobileMenuOpen = !mobileMenuOpen">
          <i :class="mobileMenuOpen ? 'pi pi-times' : 'pi pi-bars'"></i>
        </button>
      </div>

      <!-- Mobile menu -->
      <div v-if="mobileMenuOpen" class="ch-nav__mobile-menu">
        <a href="#features" class="ch-nav__mobile-link" @click="mobileMenuOpen = false">Features</a>
        <a href="#platforms" class="ch-nav__mobile-link" @click="mobileMenuOpen = false">Platforms</a>
        <a href="#ai" class="ch-nav__mobile-link" @click="mobileMenuOpen = false">AI Engine</a>
        <a href="#testimonials" class="ch-nav__mobile-link" @click="mobileMenuOpen = false">Testimonials</a>
        <div class="ch-nav__mobile-actions">
          <a v-if="isLoggedIn" href="/dashboard">
            <Button label="Dashboard" icon="pi pi-th-large" class="w-full" />
          </a>
          <template v-else>
            <a href="/login"><Button label="Sign In" outlined class="w-full" /></a>
            <a href="/register"><Button label="Get Started" class="w-full ch-btn-accent" /></a>
          </template>
        </div>
      </div>
    </nav>

    <!-- ===== HERO ===== -->
    <section class="ch-hero">
      <div class="ch-hero__bg">
        <div class="ch-hero__grid-lines"></div>
        <div class="ch-hero__glow ch-hero__glow--1"></div>
        <div class="ch-hero__glow ch-hero__glow--2"></div>
      </div>

      <div class="ch-container ch-hero__wrapper">
        <div class="ch-hero__content">
          <div class="ch-hero__badge ch-reveal" style="--delay: 0">
            <span class="ch-hero__badge-dot"></span>
            AI-Powered Customer Service Automation
          </div>

          <h1 class="ch-hero__title ch-reveal" style="--delay: 1">
            Every customer conversation,<br />
            <span class="ch-hero__title-accent">handled intelligently.</span>
          </h1>

          <p class="ch-hero__subtitle ch-reveal" style="--delay: 2">
            Deploy AI agents across WhatsApp, Messenger, Telegram &amp; LINE.
            Automate support, close sales, and delight customers 24/7.
          </p>

          <div class="ch-hero__ctas ch-reveal" style="--delay: 3">
            <a href="/register">
              <Button label="Start Free Trial" icon="pi pi-arrow-right" iconPos="right" size="large" class="ch-btn-accent ch-btn-accent--hero" />
            </a>
            <button class="ch-btn-demo" @click="showDemo = true">
              <span class="ch-btn-demo__icon"><i class="pi pi-play-circle"></i></span>
              <span>Watch Demo</span>
            </button>
          </div>

          <div class="ch-hero__proof ch-reveal" style="--delay: 4">
            <div class="ch-hero__avatars">
              <img v-for="i in 5" :key="i" :src="`https://i.pravatar.cc/48?img=${i + 10}`" :alt="`User ${i}`" class="ch-hero__avatar" />
            </div>
            <div class="ch-hero__proof-text">
              <div class="ch-hero__stars">
                <i v-for="i in 5" :key="i" class="pi pi-star-fill"></i>
              </div>
              <span>Trusted by 2,000+ businesses</span>
            </div>
          </div>
        </div>

        <!-- Floating chat mockup -->
        <div class="ch-hero__mockup ch-reveal" style="--delay: 3">
          <div class="ch-mockup">
            <div class="ch-mockup__header">
              <div class="ch-mockup__dot ch-mockup__dot--green"></div>
              <span>Live Chat &mdash; Acme Corp</span>
            </div>
            <div class="ch-mockup__body">
              <div class="ch-mockup__msg ch-mockup__msg--customer">
                <span>Hi, I'd like to check my order status for #4829</span>
              </div>
              <div class="ch-mockup__msg ch-mockup__msg--ai">
                <div class="ch-mockup__ai-badge">
                  <i class="pi pi-sparkles"></i> AI Agent
                </div>
                <span>Your order #4829 shipped yesterday via DHL. Tracking: DHL-8294710. Expected delivery: Jan 29.</span>
              </div>
              <div class="ch-mockup__msg ch-mockup__msg--customer">
                <span>That's great, thank you!</span>
              </div>
              <div class="ch-mockup__typing">
                <div class="ch-mockup__ai-badge">
                  <i class="pi pi-sparkles"></i> AI Agent
                </div>
                <div class="ch-mockup__typing-dots">
                  <span></span><span></span><span></span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== STATS TICKER ===== -->
    <section class="ch-stats">
      <div class="ch-container">
        <div class="ch-stats__grid">
          <div v-for="stat in stats" :key="stat.label" class="ch-stats__item">
            <span class="ch-stats__value">{{ stat.value }}</span>
            <span class="ch-stats__label">{{ stat.label }}</span>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== FEATURES ===== -->
    <section id="features" class="ch-features">
      <div class="ch-container">
        <div class="ch-section-header">
          <span class="ch-section-tag">Capabilities</span>
          <h2 class="ch-section-title">Built for modern<br />support teams</h2>
          <p class="ch-section-desc">
            A complete toolkit to automate, manage, and scale customer interactions.
          </p>
        </div>

        <div class="ch-features__grid">
          <div
            v-for="(feature, idx) in features"
            :key="feature.title"
            class="ch-feature-card"
            :style="{ '--card-idx': idx }"
          >
            <div class="ch-feature-card__icon">
              <i :class="feature.icon"></i>
            </div>
            <h3 class="ch-feature-card__title">{{ feature.title }}</h3>
            <p class="ch-feature-card__desc">{{ feature.description }}</p>
            <div class="ch-feature-card__shine"></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== PLATFORMS ===== -->
    <section id="platforms" class="ch-platforms">
      <div class="ch-container">
        <div class="ch-platforms__layout">
          <div class="ch-platforms__text">
            <span class="ch-section-tag">Integrations</span>
            <h2 class="ch-section-title">One inbox.<br />Every channel.</h2>
            <p class="ch-section-desc">
              Unify conversations from all messaging platforms into a single command center.
              No context-switching. No dropped threads.
            </p>

            <div class="ch-platforms__grid">
              <div v-for="platform in platforms" :key="platform.name" class="ch-platform-card">
                <div class="ch-platform-card__icon">{{ platform.emoji }}</div>
                <div>
                  <p class="ch-platform-card__name">{{ platform.name }}</p>
                  <p class="ch-platform-card__users">{{ platform.users }}</p>
                </div>
              </div>
            </div>
          </div>

          <div class="ch-platforms__visual">
            <div class="ch-inbox-mockup">
              <div class="ch-inbox-mockup__header">
                <span class="ch-inbox-mockup__title">Unified Inbox</span>
                <span class="ch-inbox-mockup__count">24 active</span>
              </div>
              <div v-for="(conv, i) in inboxConversations" :key="i" class="ch-inbox-mockup__row">
                <div class="ch-inbox-mockup__avatar" :style="{ background: conv.color }">{{ conv.initial }}</div>
                <div class="ch-inbox-mockup__content">
                  <div class="ch-inbox-mockup__top">
                    <span class="ch-inbox-mockup__name">{{ conv.name }}</span>
                    <span class="ch-inbox-mockup__platform">{{ conv.platform }}</span>
                  </div>
                  <p class="ch-inbox-mockup__preview">{{ conv.preview }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== AI ENGINE ===== -->
    <section id="ai" class="ch-ai">
      <div class="ch-container">
        <div class="ch-ai__layout">
          <div class="ch-ai__visual">
            <div class="ch-ai__diagram">
              <div class="ch-ai__node ch-ai__node--input">
                <i class="pi pi-envelope"></i>
                <span>Message In</span>
              </div>
              <div class="ch-ai__connector"></div>
              <div class="ch-ai__node ch-ai__node--brain">
                <i class="pi pi-microchip-ai"></i>
                <span>AI Engine</span>
              </div>
              <div class="ch-ai__connector"></div>
              <div class="ch-ai__node ch-ai__node--output">
                <i class="pi pi-send"></i>
                <span>Response</span>
              </div>
            </div>
            <div class="ch-ai__providers">
              <div class="ch-ai__provider" v-for="provider in aiProviders" :key="provider.name">
                <span class="ch-ai__provider-icon">{{ provider.icon }}</span>
                <span>{{ provider.name }}</span>
              </div>
            </div>
          </div>

          <div class="ch-ai__text">
            <span class="ch-section-tag">AI Engine</span>
            <h2 class="ch-section-title">Intelligence<br />that adapts.</h2>
            <p class="ch-section-desc">
              Choose from leading AI providers. Train with your knowledge base.
              Get context-aware responses that actually resolve issues.
            </p>
            <ul class="ch-ai__features">
              <li v-for="item in aiFeatures" :key="item" class="ch-ai__feature-item">
                <div class="ch-ai__check"><i class="pi pi-check"></i></div>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== TESTIMONIALS ===== -->
    <section id="testimonials" class="ch-testimonials">
      <div class="ch-container">
        <div class="ch-section-header">
          <span class="ch-section-tag">Testimonials</span>
          <h2 class="ch-section-title">Teams ship faster<br />with RChat</h2>
        </div>

        <div class="ch-testimonials__grid">
          <div
            v-for="(testimonial, idx) in testimonials"
            :key="testimonial.name"
            class="ch-testimonial-card"
            :class="{ 'ch-testimonial-card--featured': idx === 0 }"
          >
            <div class="ch-testimonial-card__stars">
              <i v-for="i in 5" :key="i" class="pi pi-star-fill"></i>
            </div>
            <blockquote class="ch-testimonial-card__quote">"{{ testimonial.quote }}"</blockquote>
            <div class="ch-testimonial-card__author">
              <img :src="testimonial.avatar" :alt="testimonial.name" class="ch-testimonial-card__avatar" />
              <div>
                <p class="ch-testimonial-card__name">{{ testimonial.name }}</p>
                <p class="ch-testimonial-card__role">{{ testimonial.role }}, {{ testimonial.company }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== CTA ===== -->
    <section class="ch-cta">
      <div class="ch-cta__bg">
        <div class="ch-cta__glow"></div>
      </div>
      <div class="ch-container ch-cta__content">
        <h2 class="ch-cta__title">
          Ready to automate your<br />customer conversations?
        </h2>
        <p class="ch-cta__desc">
          Deploy AI-powered support across every channel. Free to start. No credit card required.
        </p>
        <div class="ch-cta__actions">
          <a href="/register">
            <Button label="Start Free Trial" icon="pi pi-arrow-right" iconPos="right" size="large" class="ch-btn-accent ch-btn-accent--hero" />
          </a>
          <button class="ch-btn-demo ch-btn-demo--light" @click="showDemo = true">
            <span class="ch-btn-demo__icon"><i class="pi pi-play-circle"></i></span>
            <span>Schedule a Demo</span>
          </button>
        </div>
      </div>
    </section>

    <!-- ===== FOOTER ===== -->
    <footer class="ch-footer">
      <div class="ch-container">
        <div class="ch-footer__grid">
          <div class="ch-footer__brand">
            <a href="/" class="ch-nav__brand">
              <div class="ch-nav__logo ch-nav__logo--footer">
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect width="32" height="32" rx="8" fill="currentColor" class="text-emerald-500"/>
                  <path d="M8 12C8 10.8954 8.89543 10 10 10H22C23.1046 10 24 10.8954 24 12V18C24 19.1046 23.1046 20 22 20H18L14 24V20H10C8.89543 20 8 19.1046 8 18V12Z" fill="white"/>
                  <circle cx="13" cy="15" r="1.5" fill="currentColor" class="text-emerald-500"/>
                  <circle cx="19" cy="15" r="1.5" fill="currentColor" class="text-emerald-500"/>
                </svg>
              </div>
              <span class="ch-nav__wordmark">RChat</span>
            </a>
            <p class="ch-footer__tagline">AI-powered customer service automation for modern businesses.</p>
            <div class="ch-footer__social">
              <a href="#" class="ch-footer__social-link"><i class="pi pi-twitter"></i></a>
              <a href="#" class="ch-footer__social-link"><i class="pi pi-linkedin"></i></a>
              <a href="#" class="ch-footer__social-link"><i class="pi pi-facebook"></i></a>
              <a href="#" class="ch-footer__social-link"><i class="pi pi-github"></i></a>
            </div>
          </div>

          <div class="ch-footer__col">
            <h4 class="ch-footer__heading">Product</h4>
            <a href="#features" class="ch-footer__link">Features</a>
            <a href="#platforms" class="ch-footer__link">Integrations</a>
            <a href="#ai" class="ch-footer__link">AI Engine</a>
            <a href="#" class="ch-footer__link">API Docs</a>
          </div>

          <div class="ch-footer__col">
            <h4 class="ch-footer__heading">Company</h4>
            <a href="#" class="ch-footer__link">About</a>
            <a href="#" class="ch-footer__link">Blog</a>
            <a href="#" class="ch-footer__link">Careers</a>
            <a href="#" class="ch-footer__link">Contact</a>
          </div>

          <div class="ch-footer__col">
            <h4 class="ch-footer__heading">Legal</h4>
            <a href="#" class="ch-footer__link">Privacy</a>
            <a href="#" class="ch-footer__link">Terms</a>
            <a href="#" class="ch-footer__link">Security</a>
          </div>
        </div>

        <div class="ch-footer__bottom">
          <p>&copy; 2026 RChat. All rights reserved.</p>
        </div>
      </div>
    </footer>

    <!-- Demo Dialog -->
    <Dialog v-model:visible="showDemo" modal header="Product Demo" :style="{ width: '90vw', maxWidth: '900px' }">
      <div class="aspect-video bg-gray-900 rounded-lg flex items-center justify-center">
        <div class="text-center">
          <i class="pi pi-play-circle text-6xl text-gray-500 mb-4"></i>
          <p class="text-gray-400">Demo video coming soon</p>
        </div>
      </div>
    </Dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useThemeStore } from '../stores/theme';
import { useAuthStore } from '../stores/auth';
import { storeToRefs } from 'pinia';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';

const themeStore = useThemeStore();
const authStore = useAuthStore();
const { isDark } = storeToRefs(themeStore);

const isLoggedIn = computed(() => !!authStore.token);
const toggleTheme = () => themeStore.toggleTheme();

const showDemo = ref(false);
const scrolled = ref(false);
const mobileMenuOpen = ref(false);

const handleScroll = () => {
  scrolled.value = window.scrollY > 20;
};

onMounted(() => {
  window.addEventListener('scroll', handleScroll);
  handleScroll();
});

onUnmounted(() => {
  window.removeEventListener('scroll', handleScroll);
});

const stats = [
  { value: '10M+', label: 'Messages Handled' },
  { value: '2,000+', label: 'Active Businesses' },
  { value: '95%', label: 'Satisfaction Rate' },
  { value: '70%', label: 'Cost Reduction' },
];

const features = [
  {
    title: 'Multi-Platform Inbox',
    description: 'WhatsApp, Messenger, Telegram, and LINE unified in one dashboard. Zero context-switching.',
    icon: 'pi pi-comments'
  },
  {
    title: 'AI Auto-Response',
    description: 'GPT-4, Gemini, or Claude generate accurate replies trained on your knowledge base.',
    icon: 'pi pi-microchip-ai'
  },
  {
    title: 'Knowledge Base RAG',
    description: 'Upload docs, FAQs, and product catalogs. AI retrieves relevant context per query.',
    icon: 'pi pi-book'
  },
  {
    title: 'Analytics & Insights',
    description: 'Conversation metrics, sentiment scores, and satisfaction tracking in real-time.',
    icon: 'pi pi-chart-bar'
  },
  {
    title: 'Team Collaboration',
    description: 'Assign conversations, internal notes, role-based access, and activity logs.',
    icon: 'pi pi-users'
  },
  {
    title: 'Smart Escalation',
    description: 'AI confidence scoring triggers automatic handoff to human agents when needed.',
    icon: 'pi pi-arrow-right-arrow-left'
  }
];

const platforms = [
  { name: 'WhatsApp', emoji: '\uD83D\uDFE2', users: '2B+ users' },
  { name: 'Messenger', emoji: '\uD83D\uDFE3', users: '1.3B+ users' },
  { name: 'Telegram', emoji: '\uD83D\uDD35', users: '700M+ users' },
  { name: 'LINE', emoji: '\uD83D\uDFE2', users: '200M+ users' },
];

const inboxConversations = [
  { name: 'Sarah M.', initial: 'S', color: '#10b981', platform: 'WhatsApp', preview: 'Hi, can I check my order status?' },
  { name: 'Alex K.', initial: 'A', color: '#8b5cf6', platform: 'Messenger', preview: 'Do you ship internationally?' },
  { name: 'Yuki T.', initial: 'Y', color: '#06b6d4', platform: 'LINE', preview: 'I need help with returns...' },
  { name: 'Marco R.', initial: 'M', color: '#f59e0b', platform: 'Telegram', preview: 'Product recommendation please' },
];

const aiProviders = [
  { name: 'OpenAI', icon: '\u2B50' },
  { name: 'Gemini', icon: '\uD83D\uDC8E' },
  { name: 'Claude', icon: '\uD83E\uDDE0' },
];

const aiFeatures = [
  'Choose from OpenAI GPT-4, Google Gemini, or Claude',
  'Train with your own knowledge base and FAQs',
  'Automatic sentiment analysis on every conversation',
  'Smart conversation summaries and tagging',
  'Confidence-based human handoff',
  'Continuous learning from agent interactions'
];

const testimonials = [
  {
    name: 'Sarah Chen',
    role: 'CS Manager',
    company: 'TechStart Inc.',
    avatar: 'https://i.pravatar.cc/100?img=1',
    quote: 'RChat reduced our response time by 80%. Our customers love the instant AI responses, and our team can focus on complex issues.'
  },
  {
    name: 'Michael Rodriguez',
    role: 'Head of Support',
    company: 'GlobalRetail',
    avatar: 'https://i.pravatar.cc/100?img=3',
    quote: 'The multi-platform support is a game-changer. We manage WhatsApp, Messenger, and Telegram from one dashboard.'
  },
  {
    name: 'Emily Watson',
    role: 'CEO',
    company: 'StartupHub',
    avatar: 'https://i.pravatar.cc/100?img=5',
    quote: 'We scaled support 10x without hiring. The AI handles 90% of inquiries automatically from our knowledge base.'
  },
  {
    name: 'David Kim',
    role: 'Operations Director',
    company: 'EcomStore',
    avatar: 'https://i.pravatar.cc/100?img=7',
    quote: 'The analytics dashboard gives incredible insights into customer sentiment. Data-driven decisions every day.'
  },
  {
    name: 'Lisa Thompson',
    role: 'Support Lead',
    company: 'FinanceApp',
    avatar: 'https://i.pravatar.cc/100?img=9',
    quote: 'The seamless AI-to-human handoff is brilliant. Complex issues get routed to the right team member automatically.'
  },
  {
    name: 'James Wilson',
    role: 'CTO',
    company: 'CloudServices',
    avatar: 'https://i.pravatar.cc/100?img=11',
    quote: 'Integration was a breeze. Up and running in less than a day. The API is well-documented and support is responsive.'
  }
];
</script>

<style scoped>
/* ==========================================
   DESIGN SYSTEM VARIABLES
   ========================================== */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&family=JetBrains+Mono:wght@400;500;600&display=swap');

.ch-landing {
  --ch-bg: #fafbfc;
  --ch-bg-alt: #f1f3f5;
  --ch-surface: #ffffff;
  --ch-surface-hover: #f8f9fa;
  --ch-border: #e5e7eb;
  --ch-border-subtle: #f0f0f0;
  --ch-text: #0f172a;
  --ch-text-secondary: #64748b;
  --ch-text-muted: #94a3b8;
  --ch-accent: #10b981;
  --ch-accent-light: #d1fae5;
  --ch-accent-glow: rgba(16, 185, 129, 0.15);
  --ch-radius: 12px;
  --ch-radius-lg: 20px;
  --ch-font-display: 'DM Sans', system-ui, -apple-system, sans-serif;
  --ch-font-body: 'DM Sans', system-ui, -apple-system, sans-serif;
  --ch-font-mono: 'JetBrains Mono', 'Fira Code', monospace;

  font-family: var(--ch-font-body);
  color: var(--ch-text);
  background: var(--ch-bg);
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
}

.ch-landing.ch-dark {
  --ch-bg: #0a0e17;
  --ch-bg-alt: #111827;
  --ch-surface: #151c2c;
  --ch-surface-hover: #1e2740;
  --ch-border: #1e293b;
  --ch-border-subtle: #1a2236;
  --ch-text: #f1f5f9;
  --ch-text-secondary: #94a3b8;
  --ch-text-muted: #64748b;
  --ch-accent: #34d399;
  --ch-accent-light: rgba(52, 211, 153, 0.1);
  --ch-accent-glow: rgba(52, 211, 153, 0.08);
}

/* ===== GRAIN TEXTURE ===== */
.ch-grain {
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 1000;
  opacity: 0.025;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
  background-repeat: repeat;
  background-size: 128px;
}

.ch-dark .ch-grain { opacity: 0.04; }

/* ===== CONTAINER ===== */
.ch-container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }

/* ===== REVEAL ANIMATION ===== */
.ch-reveal {
  animation: chReveal 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--delay, 0) * 0.12s);
}

@keyframes chReveal {
  from { opacity: 0; transform: translateY(24px); }
  to { opacity: 1; transform: translateY(0); }
}

/* ===== SECTION COMMON ===== */
.ch-section-header { text-align: center; margin-bottom: 64px; }

.ch-section-tag {
  display: inline-block;
  font-family: var(--ch-font-mono);
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ch-accent);
  background: var(--ch-accent-light);
  padding: 6px 16px;
  border-radius: 100px;
  margin-bottom: 20px;
  border: 1px solid rgba(16, 185, 129, 0.2);
}

.ch-dark .ch-section-tag { border-color: rgba(52, 211, 153, 0.2); }

.ch-section-title {
  font-family: var(--ch-font-display);
  font-size: clamp(32px, 5vw, 48px);
  font-weight: 700;
  line-height: 1.15;
  letter-spacing: -0.03em;
  color: var(--ch-text);
  margin: 0 0 16px;
}

.ch-section-desc {
  font-size: 18px;
  line-height: 1.6;
  color: var(--ch-text-secondary);
  max-width: 560px;
  margin: 0;
}

.ch-section-header .ch-section-desc { margin: 0 auto; }

/* ==========================================
   NAVIGATION
   ========================================== */
.ch-nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  transition: all 0.3s ease;
  background: transparent;
}

.ch-nav--scrolled {
  background: color-mix(in srgb, var(--ch-bg) 85%, transparent);
  backdrop-filter: blur(16px) saturate(180%);
  -webkit-backdrop-filter: blur(16px) saturate(180%);
  border-bottom: 1px solid var(--ch-border-subtle);
}

.ch-nav__inner {
  display: flex; align-items: center; justify-content: space-between;
  height: 64px; gap: 32px;
}

.ch-nav__brand {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none; color: var(--ch-text);
}

.ch-nav__logo { width: 32px; height: 32px; }
.ch-nav__logo--footer { width: 28px; height: 28px; }

.ch-nav__wordmark {
  font-family: var(--ch-font-display);
  font-size: 20px; font-weight: 700; letter-spacing: -0.02em;
}

.ch-nav__links { display: none; align-items: center; gap: 32px; }
@media (min-width: 768px) { .ch-nav__links { display: flex; } }

.ch-nav__link {
  font-size: 14px; font-weight: 500; color: var(--ch-text-secondary);
  text-decoration: none; transition: color 0.2s; position: relative;
}
.ch-nav__link:hover { color: var(--ch-text); }
.ch-nav__link::after {
  content: ''; position: absolute; bottom: -4px; left: 0;
  width: 0; height: 2px; background: var(--ch-accent);
  transition: width 0.2s ease; border-radius: 1px;
}
.ch-nav__link:hover::after { width: 100%; }

.ch-nav__actions { display: none; align-items: center; gap: 12px; }
@media (min-width: 768px) { .ch-nav__actions { display: flex; } }

.ch-nav__signin {
  font-size: 14px; font-weight: 500; color: var(--ch-text-secondary);
  text-decoration: none; padding: 8px 16px; border-radius: 8px; transition: all 0.2s;
}
.ch-nav__signin:hover { color: var(--ch-text); background: var(--ch-surface-hover); }

.ch-nav__mobile-toggle {
  display: flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border: none; background: transparent;
  color: var(--ch-text); font-size: 20px; cursor: pointer; border-radius: 8px;
}
@media (min-width: 768px) { .ch-nav__mobile-toggle { display: none; } }
.ch-nav__mobile-toggle:hover { background: var(--ch-surface-hover); }

.ch-nav__mobile-menu {
  background: var(--ch-surface); border-top: 1px solid var(--ch-border);
  padding: 16px 24px 24px; display: flex; flex-direction: column; gap: 4px;
}

.ch-nav__mobile-link {
  display: block; padding: 12px 16px; font-size: 15px; font-weight: 500;
  color: var(--ch-text-secondary); text-decoration: none; border-radius: 8px; transition: all 0.2s;
}
.ch-nav__mobile-link:hover { color: var(--ch-text); background: var(--ch-surface-hover); }

.ch-nav__mobile-actions {
  display: flex; flex-direction: column; gap: 8px;
  margin-top: 12px; padding-top: 16px; border-top: 1px solid var(--ch-border);
}
.ch-nav__mobile-actions a { display: block; }

/* ===== BUTTONS ===== */
.ch-btn-icon {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border: 1px solid var(--ch-border);
  border-radius: 8px; background: var(--ch-surface); color: var(--ch-text-secondary);
  cursor: pointer; transition: all 0.2s; font-size: 15px;
}
.ch-btn-icon:hover { border-color: var(--ch-text-muted); color: var(--ch-text); }

.ch-btn-accent {
  background: var(--ch-accent) !important; border-color: var(--ch-accent) !important;
  color: #0a0e17 !important; font-weight: 600 !important;
}
.ch-btn-accent:hover { filter: brightness(1.1); }

.ch-btn-accent--hero {
  font-size: 16px !important; padding: 12px 32px !important; border-radius: 12px !important;
  box-shadow: 0 0 40px var(--ch-accent-glow), 0 4px 12px rgba(0, 0, 0, 0.1);
}

.ch-btn-demo {
  display: inline-flex; align-items: center; gap: 10px;
  padding: 12px 28px; font-size: 16px; font-weight: 500;
  font-family: var(--ch-font-body); color: var(--ch-text);
  background: transparent; border: 1px solid var(--ch-border);
  border-radius: 12px; cursor: pointer; transition: all 0.2s;
}
.ch-btn-demo:hover { border-color: var(--ch-text-muted); background: var(--ch-surface); }

.ch-btn-demo--light { color: white; border-color: rgba(255, 255, 255, 0.25); }
.ch-btn-demo--light:hover { border-color: rgba(255, 255, 255, 0.5); background: rgba(255, 255, 255, 0.05); }

.ch-btn-demo__icon { font-size: 20px; line-height: 1; display: flex; }

/* ==========================================
   HERO
   ========================================== */
.ch-hero {
  position: relative; padding: 140px 0 100px; overflow: hidden;
}

@media (min-width: 1024px) { .ch-hero { padding: 160px 0 120px; } }

.ch-hero__bg { position: absolute; inset: 0; overflow: hidden; }

.ch-hero__grid-lines {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(var(--ch-border-subtle) 1px, transparent 1px),
    linear-gradient(90deg, var(--ch-border-subtle) 1px, transparent 1px);
  background-size: 64px 64px; opacity: 0.5;
  mask-image: radial-gradient(ellipse at center, black 0%, transparent 70%);
  -webkit-mask-image: radial-gradient(ellipse at center, black 0%, transparent 70%);
}

.ch-hero__glow { position: absolute; border-radius: 50%; filter: blur(100px); opacity: 0.4; }
.ch-hero__glow--1 { width: 600px; height: 600px; background: var(--ch-accent); top: -200px; right: -100px; opacity: 0.08; }
.ch-hero__glow--2 { width: 400px; height: 400px; background: #6366f1; bottom: -100px; left: -100px; opacity: 0.06; }

.ch-hero__wrapper {
  position: relative; z-index: 2;
  display: flex; flex-direction: column; align-items: center;
}

@media (min-width: 1024px) {
  .ch-hero__wrapper {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 64px; align-items: center;
  }
}

.ch-hero__content {
  text-align: center;
}

@media (min-width: 1024px) { .ch-hero__content { text-align: left; } }

.ch-hero__badge {
  display: inline-flex; align-items: center; gap: 8px;
  font-family: var(--ch-font-mono); font-size: 13px; font-weight: 500;
  color: var(--ch-accent); background: var(--ch-accent-light);
  padding: 8px 18px; border-radius: 100px; margin-bottom: 28px;
  border: 1px solid rgba(16, 185, 129, 0.15);
}
.ch-dark .ch-hero__badge { border-color: rgba(52, 211, 153, 0.15); }

.ch-hero__badge-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--ch-accent); animation: chPulse 2s ease-in-out infinite;
}

@keyframes chPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

.ch-hero__title {
  font-family: var(--ch-font-display);
  font-size: clamp(36px, 6vw, 58px);
  font-weight: 800; line-height: 1.08; letter-spacing: -0.035em;
  color: var(--ch-text); margin: 0 0 24px;
}

.ch-hero__title-accent {
  background: linear-gradient(135deg, var(--ch-accent), #6ee7b7);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}

.ch-dark .ch-hero__title-accent {
  background: linear-gradient(135deg, var(--ch-accent), #a7f3d0);
  -webkit-background-clip: text; background-clip: text;
}

.ch-hero__subtitle {
  font-size: 18px; line-height: 1.7; color: var(--ch-text-secondary);
  margin: 0 0 36px; max-width: 520px;
}

@media (max-width: 1023px) { .ch-hero__subtitle { margin-left: auto; margin-right: auto; } }

.ch-hero__ctas {
  display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 48px; justify-content: center;
}
@media (min-width: 1024px) { .ch-hero__ctas { justify-content: flex-start; } }

.ch-hero__proof { display: flex; align-items: center; gap: 16px; justify-content: center; }
@media (min-width: 1024px) { .ch-hero__proof { justify-content: flex-start; } }

.ch-hero__avatars { display: flex; }
.ch-hero__avatar {
  width: 36px; height: 36px; border-radius: 50%;
  border: 2px solid var(--ch-bg); margin-left: -8px;
}
.ch-hero__avatar:first-child { margin-left: 0; }

.ch-hero__proof-text { display: flex; flex-direction: column; gap: 2px; }
.ch-hero__stars { display: flex; gap: 2px; color: #f59e0b; font-size: 13px; }
.ch-hero__proof-text > span { font-size: 13px; color: var(--ch-text-muted); }

/* Hero Chat Mockup */
.ch-hero__mockup { margin-top: 48px; }
@media (min-width: 1024px) { .ch-hero__mockup { margin-top: 0; } }

.ch-mockup {
  background: var(--ch-surface); border: 1px solid var(--ch-border);
  border-radius: var(--ch-radius-lg); overflow: hidden;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04), 0 20px 60px rgba(0, 0, 0, 0.08), 0 0 80px var(--ch-accent-glow);
}

.ch-mockup__header {
  display: flex; align-items: center; gap: 10px;
  padding: 16px 20px; border-bottom: 1px solid var(--ch-border);
  font-size: 14px; font-weight: 600; color: var(--ch-text);
}

.ch-mockup__dot { width: 10px; height: 10px; border-radius: 50%; }
.ch-mockup__dot--green { background: #10b981; box-shadow: 0 0 8px rgba(16, 185, 129, 0.4); }

.ch-mockup__body {
  padding: 20px; display: flex; flex-direction: column;
  gap: 14px; min-height: 220px;
}

.ch-mockup__msg {
  max-width: 85%; padding: 12px 16px; border-radius: 16px;
  font-size: 14px; line-height: 1.5;
}

.ch-mockup__msg--customer {
  background: var(--ch-bg-alt); color: var(--ch-text);
  align-self: flex-end; border-bottom-right-radius: 4px;
}

.ch-mockup__msg--ai {
  background: var(--ch-accent-light); color: var(--ch-text);
  align-self: flex-start; border-bottom-left-radius: 4px;
}
.ch-dark .ch-mockup__msg--ai { background: rgba(52, 211, 153, 0.08); }

.ch-mockup__ai-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; font-weight: 600; color: var(--ch-accent); margin-bottom: 6px;
}

.ch-mockup__typing { align-self: flex-start; padding: 12px 16px; }

.ch-mockup__typing-dots { display: flex; gap: 4px; }
.ch-mockup__typing-dots span {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--ch-text-muted); animation: chTyping 1.4s ease-in-out infinite;
}
.ch-mockup__typing-dots span:nth-child(2) { animation-delay: 0.2s; }
.ch-mockup__typing-dots span:nth-child(3) { animation-delay: 0.4s; }

@keyframes chTyping {
  0%, 60%, 100% { opacity: 0.3; transform: scale(0.85); }
  30% { opacity: 1; transform: scale(1); }
}

/* ==========================================
   STATS
   ========================================== */
.ch-stats {
  padding: 48px 0; border-top: 1px solid var(--ch-border);
  border-bottom: 1px solid var(--ch-border); background: var(--ch-surface);
}

.ch-stats__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
@media (min-width: 768px) { .ch-stats__grid { grid-template-columns: repeat(4, 1fr); } }

.ch-stats__item { text-align: center; }

.ch-stats__value {
  display: block; font-family: var(--ch-font-display);
  font-size: clamp(28px, 4vw, 40px); font-weight: 800;
  letter-spacing: -0.03em; color: var(--ch-text);
}

.ch-stats__label { display: block; font-size: 14px; color: var(--ch-text-muted); margin-top: 4px; }

/* ==========================================
   FEATURES
   ========================================== */
.ch-features { padding: 100px 0; }

.ch-features__grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
@media (min-width: 640px) { .ch-features__grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .ch-features__grid { grid-template-columns: repeat(3, 1fr); } }

.ch-feature-card {
  position: relative; padding: 32px; background: var(--ch-surface);
  border: 1px solid var(--ch-border); border-radius: var(--ch-radius-lg);
  transition: all 0.3s ease; overflow: hidden;
}

.ch-feature-card:hover {
  border-color: var(--ch-accent); transform: translateY(-4px);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.06), 0 0 0 1px var(--ch-accent);
}

.ch-dark .ch-feature-card:hover {
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2), 0 0 0 1px var(--ch-accent), 0 0 60px var(--ch-accent-glow);
}

.ch-feature-card__shine {
  position: absolute; top: -100%; left: -100%; width: 300%; height: 300%;
  background: radial-gradient(circle at center, var(--ch-accent-glow) 0%, transparent 60%);
  opacity: 0; transition: opacity 0.4s ease; pointer-events: none;
}
.ch-feature-card:hover .ch-feature-card__shine { opacity: 1; }

.ch-feature-card__icon {
  width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;
  border-radius: 12px; font-size: 22px; color: var(--ch-accent);
  background: var(--ch-accent-light); margin-bottom: 20px; position: relative;
}

.ch-feature-card__title {
  font-family: var(--ch-font-display); font-size: 18px; font-weight: 700;
  color: var(--ch-text); margin: 0 0 10px; position: relative;
}

.ch-feature-card__desc {
  font-size: 15px; line-height: 1.6; color: var(--ch-text-secondary); margin: 0; position: relative;
}

/* ==========================================
   PLATFORMS
   ========================================== */
.ch-platforms { padding: 100px 0; background: var(--ch-bg-alt); }

.ch-platforms__layout { display: grid; grid-template-columns: 1fr; gap: 64px; align-items: center; }
@media (min-width: 1024px) { .ch-platforms__layout { grid-template-columns: 1fr 1fr; } }

.ch-platforms__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 32px; }

.ch-platform-card {
  display: flex; align-items: center; gap: 12px; padding: 16px;
  background: var(--ch-surface); border: 1px solid var(--ch-border);
  border-radius: var(--ch-radius); transition: all 0.2s;
}
.ch-platform-card:hover { border-color: var(--ch-accent); }

.ch-platform-card__icon { font-size: 28px; line-height: 1; }
.ch-platform-card__name { font-size: 15px; font-weight: 600; color: var(--ch-text); margin: 0; }
.ch-platform-card__users { font-size: 13px; color: var(--ch-text-muted); margin: 0; }

/* Inbox Mockup */
.ch-inbox-mockup {
  background: var(--ch-surface); border: 1px solid var(--ch-border);
  border-radius: var(--ch-radius-lg); overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
}
.ch-dark .ch-inbox-mockup { box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); }

.ch-inbox-mockup__header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 20px; border-bottom: 1px solid var(--ch-border);
}

.ch-inbox-mockup__title { font-weight: 700; font-size: 15px; color: var(--ch-text); }

.ch-inbox-mockup__count {
  font-family: var(--ch-font-mono); font-size: 12px; color: var(--ch-accent);
  background: var(--ch-accent-light); padding: 4px 12px; border-radius: 100px;
}

.ch-inbox-mockup__row {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 20px; border-bottom: 1px solid var(--ch-border-subtle);
  transition: background 0.15s;
}
.ch-inbox-mockup__row:last-child { border-bottom: none; }
.ch-inbox-mockup__row:hover { background: var(--ch-surface-hover); }

.ch-inbox-mockup__avatar {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; font-weight: 700; color: white; flex-shrink: 0;
}

.ch-inbox-mockup__content { flex: 1; min-width: 0; }

.ch-inbox-mockup__top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.ch-inbox-mockup__name { font-size: 14px; font-weight: 600; color: var(--ch-text); }

.ch-inbox-mockup__platform {
  font-family: var(--ch-font-mono); font-size: 11px; font-weight: 500;
  color: var(--ch-text-muted); background: var(--ch-bg-alt);
  padding: 2px 8px; border-radius: 4px;
}

.ch-inbox-mockup__preview {
  font-size: 13px; color: var(--ch-text-muted); margin: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* ==========================================
   AI ENGINE
   ========================================== */
.ch-ai { padding: 100px 0; }

.ch-ai__layout { display: grid; grid-template-columns: 1fr; gap: 64px; align-items: center; }
@media (min-width: 1024px) { .ch-ai__layout { grid-template-columns: 1fr 1fr; } }

.ch-ai__diagram {
  display: flex; align-items: center; justify-content: center;
  gap: 0; margin-bottom: 32px; flex-wrap: wrap;
}
@media (min-width: 640px) { .ch-ai__diagram { flex-wrap: nowrap; } }

.ch-ai__node {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 20px 24px; background: var(--ch-surface); border: 1px solid var(--ch-border);
  border-radius: var(--ch-radius); font-size: 13px; font-weight: 600;
  color: var(--ch-text); transition: all 0.3s;
}
.ch-ai__node i { font-size: 24px; color: var(--ch-text-secondary); }

.ch-ai__node--brain { background: var(--ch-accent-light); border-color: var(--ch-accent); }
.ch-ai__node--brain i { color: var(--ch-accent); }

.ch-ai__connector {
  width: 40px; height: 2px; background: var(--ch-border);
  position: relative; flex-shrink: 0;
}
.ch-ai__connector::after {
  content: ''; position: absolute; right: -4px; top: -3px;
  border: 4px solid transparent; border-left-color: var(--ch-border);
}

.ch-ai__providers { display: flex; gap: 12px; justify-content: center; }

.ch-ai__provider {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 18px; background: var(--ch-surface); border: 1px solid var(--ch-border);
  border-radius: 100px; font-size: 13px; font-weight: 500; color: var(--ch-text-secondary);
}
.ch-ai__provider-icon { font-size: 16px; }

.ch-ai__features { list-style: none; padding: 0; margin: 32px 0 0; display: flex; flex-direction: column; gap: 14px; }

.ch-ai__feature-item {
  display: flex; align-items: flex-start; gap: 12px;
  font-size: 15px; line-height: 1.5; color: var(--ch-text-secondary);
}

.ch-ai__check {
  width: 22px; height: 22px; border-radius: 6px;
  background: var(--ch-accent-light); color: var(--ch-accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; flex-shrink: 0; margin-top: 2px;
}

/* ==========================================
   TESTIMONIALS
   ========================================== */
.ch-testimonials { padding: 100px 0; background: var(--ch-bg-alt); }

.ch-testimonials__grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
@media (min-width: 640px) { .ch-testimonials__grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .ch-testimonials__grid { grid-template-columns: repeat(3, 1fr); } }

.ch-testimonial-card {
  padding: 28px; background: var(--ch-surface); border: 1px solid var(--ch-border);
  border-radius: var(--ch-radius-lg); display: flex; flex-direction: column; transition: all 0.3s;
}
.ch-testimonial-card:hover { border-color: var(--ch-accent); transform: translateY(-2px); }

.ch-testimonial-card--featured { border-color: var(--ch-accent); background: var(--ch-accent-light); }
.ch-dark .ch-testimonial-card--featured { background: rgba(52, 211, 153, 0.04); }

.ch-testimonial-card__stars { display: flex; gap: 2px; color: #f59e0b; font-size: 13px; margin-bottom: 16px; }

.ch-testimonial-card__quote {
  font-size: 15px; line-height: 1.65; color: var(--ch-text-secondary);
  margin: 0 0 24px; flex: 1;
}

.ch-testimonial-card__author { display: flex; align-items: center; gap: 12px; }
.ch-testimonial-card__avatar { width: 44px; height: 44px; border-radius: 10px; object-fit: cover; }
.ch-testimonial-card__name { font-size: 14px; font-weight: 700; color: var(--ch-text); margin: 0; }
.ch-testimonial-card__role { font-size: 13px; color: var(--ch-text-muted); margin: 0; }

/* ==========================================
   CTA
   ========================================== */
.ch-cta {
  position: relative; padding: 100px 0; background: #0a0e17; overflow: hidden;
}

.ch-cta__bg { position: absolute; inset: 0; }
.ch-cta__glow {
  position: absolute; width: 600px; height: 400px;
  background: var(--ch-accent); filter: blur(200px); opacity: 0.1;
  top: 50%; left: 50%; transform: translate(-50%, -50%);
}

.ch-cta__content {
  position: relative; z-index: 2; text-align: center;
  max-width: 640px; margin: 0 auto;
}

.ch-cta__title {
  font-family: var(--ch-font-display);
  font-size: clamp(28px, 4vw, 44px); font-weight: 800;
  line-height: 1.15; letter-spacing: -0.03em; color: white; margin: 0 0 20px;
}

.ch-cta__desc { font-size: 18px; line-height: 1.6; color: #94a3b8; margin: 0 0 40px; }
.ch-cta__actions { display: flex; flex-wrap: wrap; gap: 16px; justify-content: center; }

/* ==========================================
   FOOTER
   ========================================== */
.ch-footer {
  padding: 64px 0 32px; background: var(--ch-bg-alt);
  border-top: 1px solid var(--ch-border);
}

.ch-footer__grid { display: grid; grid-template-columns: 1fr; gap: 40px; margin-bottom: 48px; }
@media (min-width: 768px) { .ch-footer__grid { grid-template-columns: 2fr 1fr 1fr 1fr; } }

.ch-footer__brand { max-width: 300px; }
.ch-footer__tagline { font-size: 15px; color: var(--ch-text-muted); margin: 16px 0 20px; line-height: 1.6; }

.ch-footer__social { display: flex; gap: 8px; }
.ch-footer__social-link {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 8px;
  color: var(--ch-text-muted); background: var(--ch-surface);
  border: 1px solid var(--ch-border); text-decoration: none;
  transition: all 0.2s; font-size: 15px;
}
.ch-footer__social-link:hover { color: var(--ch-text); border-color: var(--ch-text-muted); }

.ch-footer__col { display: flex; flex-direction: column; gap: 10px; }

.ch-footer__heading {
  font-size: 13px; font-weight: 700; letter-spacing: 0.04em;
  text-transform: uppercase; color: var(--ch-text); margin: 0 0 4px;
}

.ch-footer__link {
  font-size: 14px; color: var(--ch-text-muted);
  text-decoration: none; transition: color 0.2s;
}
.ch-footer__link:hover { color: var(--ch-text); }

.ch-footer__bottom {
  padding-top: 24px; border-top: 1px solid var(--ch-border); text-align: center;
}
.ch-footer__bottom p { font-size: 13px; color: var(--ch-text-muted); margin: 0; }
</style>
