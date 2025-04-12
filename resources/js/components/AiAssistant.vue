<template>
  <!-- The container classes change based on dark mode -->
  <div class="ai-assistant-container" :class="{ 'dark-mode': isDarkMode, 'light-mode': !isDarkMode }">
    <div class="ai-assistant">
      <!-- Header Section -->
      <header class="assistant-header">
        <div class="header-left">
          <img src="/public/cg-logo.png" alt="Modzee Logo" class="logo" />
          <h2>AI Assistant</h2>
        </div>
        <div class="header-controls">
          <select 
            id="persona" 
            v-model="selectedPersona" 
            class="persona-select" 
            title="Select Assistant Persona"
            aria-label="Select Assistant Persona"
          >
            <option 
              v-for="persona in personas" 
              :key="persona.value" 
              :value="persona.value"
            >
              {{ persona.label }}
            </option>
          </select>
          <button 
            class="theme-toggle" 
            @click="toggleTheme" 
            title="Toggle Light/Dark Mode"
            aria-label="Toggle Light/Dark Mode"
          >
            <span v-if="isDarkMode">☀️</span>
            <span v-else>🌙</span>
          </button>
        </div>
      </header>

      <!-- Main Conversation Area -->
      <div class="conversation-area" ref="conversationContainer">
        <transition name="fade">
          <div v-if="conversationHistory.length === 0" class="empty-state">
            <div class="empty-state-icon">✨</div>
            <p>Ready to assist!</p>
            <span>Ask anything or try generating a report.</span>
          </div>
        </transition>

        <div class="conversation-history">
          <transition-group name="message">
            <div
              v-for="(item, index) in conversationHistory"
              :key="item.id || `message-${index}`"
              class="conversation-item"
              :class="[ item.role === 'user' ? 'user-message' : 'ai-message', { 'with-animation': !isInitialLoad } ]"
            >
              <div class="message-bubble">
                <strong class="message-sender">{{ item.role === 'user' ? 'You' : 'AI Assistant' }}</strong>
                <div v-if="item.role === 'user'" class="message-content user-content">{{ item.prompt }}</div>
                <div v-else class="message-content markdown-content" v-html="formatMarkdown(item.response)"></div>
                <div class="message-meta">
                  <small class="timestamp">{{ formatTimestamp(item.timestamp) }}</small>
                  <!-- Wrap feedback section in a template so adjacent if/else work correctly -->
                  <template v-if="item.role === 'ai'">
                    <div v-if="item.id && !item.feedbackGiven" class="feedback-buttons">
                      <button 
                        @click="rateResponse(item.id, 'helpful')" 
                        class="feedback-btn helpful" 
                        title="Helpful"
                        aria-label="Mark as helpful"
                      >👍</button>
                      <button 
                        @click="rateResponse(item.id, 'not_helpful')" 
                        class="feedback-btn not-helpful" 
                        title="Not Helpful"
                        aria-label="Mark as not helpful"
                      >👎</button>
                    </div>
                    <div v-else-if="item.feedbackGiven" class="feedback-given" title="Feedback submitted">✔️</div>
                  </template>
                </div>
              </div>
            </div>
          </transition-group>
          
          <div v-if="isStreaming || isLoading" class="conversation-item ai-message loading-indicator-container">
            <div class="message-bubble">
              <strong class="message-sender">AI Assistant</strong>
              <div class="typing-indicator" aria-label="AI is typing">
                <span></span><span></span><span></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Input Area -->
      <footer class="input-area">
        <transition name="fade">
          <div v-if="error" class="error-message">
            <p>{{ error }}</p>
            <button @click="dismissError" class="dismiss-btn" title="Dismiss Error" aria-label="Dismiss Error">×</button>
          </div>
        </transition>
        
        <div class="input-wrapper">
          <textarea
            v-model="userPrompt"
            placeholder="Ask the AI..."
            :disabled="isLoading || isStreaming"
            @keyup.enter.exact="submitOnEnter"
            @keyup.ctrl.enter="submitPrompt"
            @keyup.shift.enter="addLineBreak"
            ref="promptInput"
            rows="1"
            @input="autoGrowTextarea"
            aria-label="Message input"
          ></textarea>
          
          <button
            v-if="recognition && !isListening"
            @click="startVoiceInput"
            class="input-action-btn voice-btn"
            :disabled="isLoading || isStreaming"
            title="Start Voice Input"
            aria-label="Start Voice Input"
          >🎤</button>
          
          <button
            v-if="recognition && isListening"
            @click="stopVoiceInput"
            class="input-action-btn voice-btn listening"
            title="Stop Listening"
            aria-label="Stop Listening"
          >⏹️</button>
          
          <button
            @click="submitPrompt"
            :disabled="isLoading || isStreaming || !userPrompt.trim()"
            class="input-action-btn submit-btn"
            title="Send Message (Enter)"
            aria-label="Send Message"
          >
            <span v-if="isLoading || isStreaming">⏳</span>
            <span v-else>➤</span>
          </button>
        </div>
        
        <div class="action-buttons">
          <button
            @click="generateReport"
            :disabled="isLoading || isStreaming"
            class="secondary-action-btn"
            title="Generate Team Report"
            aria-label="Generate Team Report"
          >
            📄 Generate Report
          </button>
          
          <button
            @click="saveChat"
            :disabled="isLoading || isStreaming || conversationHistory.length === 0"
            class="secondary-action-btn"
            title="Save current conversation to a file"
            aria-label="Save Chat"
          >
            💾 Save Chat
          </button>
          
          <button
            @click="clearConversation"
            :disabled="isLoading || isStreaming || conversationHistory.length === 0"
            class="secondary-action-btn clear-btn"
            title="Clear current conversation"
            aria-label="Clear Chat"
          >
            🗑️ Clear Chat
          </button>
        </div>
      </footer>
    </div>
  </div>
</template>

<script>
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import aiService from '../services/aiService';
import debounce from 'lodash/debounce';

export default {
  name: 'AiAssistant',
  data() {
    return {
      userPrompt: '',
      isLoading: false,
      isStreaming: false,
      isListening: false,
      error: null,
      selectedPersona: 'general',
      conversationHistory: [],
      recognition: null,
      isAuthenticated: true,
      isDarkMode: this.detectPreferredColorScheme(),
      isInitialLoad: true,
      personas: [
        { label: 'General Assistant', value: 'general' },
        { label: 'Sales Analyst', value: 'sales' },
        { label: 'HR Advisor', value: 'hr' },
        { label: 'Technical Advisor', value: 'technical' }
      ],
    };
  },
  
  computed: {
    previousMessages() {
      return this.conversationHistory.map(item => ({
        role: item.role === 'user' ? 'user' : 'assistant',
        content: item.role === 'user' ? item.prompt : item.response,
      }));
    }
  },
  
  watch: {
    conversationHistory: {
      handler(newValue) {
        localStorage.setItem('aiConversationHistory', JSON.stringify(newValue));
        this.scrollToBottom();
      },
      deep: true
    },
    
    selectedPersona(newValue) {
      localStorage.setItem('aiSelectedPersona', newValue);
    },
    
    isDarkMode(newValue) {
      localStorage.setItem('aiDarkMode', newValue ? 'true' : 'false');
    }
  },
  
  created() {
    this.debouncedAutoGrow = debounce(this.autoGrowTextarea, 150);
    
    // Listen for system color scheme changes
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', this.updateColorScheme);
    }
  },
  
  mounted() {
    this.loadConversation();
    this.initSpeechRecognition();
    
    this.$nextTick(() => {
      this.autoGrowTextarea();
      setTimeout(() => {
        this.isInitialLoad = false;
      }, 500);
    });
  },
  
  beforeUnmount() {
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').removeEventListener('change', this.updateColorScheme);
    }
    
    if (this.recognition) {
      this.recognition.onend = null;
      this.recognition.onresult = null;
      this.recognition.onerror = null;
      if (this.isListening) {
        this.recognition.stop();
      }
    }
  },
  
  methods: {
    detectPreferredColorScheme() {
      const savedMode = localStorage.getItem('aiDarkMode');
      if (savedMode !== null) {
        return savedMode === 'true';
      }
      if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return true;
      }
      return false;
    },
    
    updateColorScheme(e) {
      if (localStorage.getItem('aiDarkMode') === null) {
        this.isDarkMode = e.matches;
      }
    },
    
    toggleTheme() {
      this.isDarkMode = !this.isDarkMode;
    },
    
    loadConversation() {
      const savedHistory = localStorage.getItem('aiConversationHistory');
      const savedPersona = localStorage.getItem('aiSelectedPersona');
      
      if (savedHistory) {
        try {
          const parsedHistory = JSON.parse(savedHistory);
          if (Array.isArray(parsedHistory)) {
            this.conversationHistory = parsedHistory;
          } else {
            console.error('Invalid conversation history format found in localStorage.');
            localStorage.removeItem('aiConversationHistory');
          }
        } catch (e) {
          console.error('Error parsing saved conversation history:', e);
          localStorage.removeItem('aiConversationHistory');
        }
      }
      
      if (savedPersona) {
        if (this.personas.some(p => p.value === savedPersona)) {
          this.selectedPersona = savedPersona;
        } else {
          localStorage.removeItem('aiSelectedPersona');
        }
      }
      
      this.scrollToBottom();
    },
    
    initSpeechRecognition() {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      
      if (SpeechRecognition) {
        this.recognition = new SpeechRecognition();
        this.recognition.continuous = false;
        this.recognition.interimResults = false;
        this.recognition.lang = 'en-US';

        this.recognition.onresult = (event) => {
          this.userPrompt = event.results[0][0].transcript;
          this.isListening = false;
          this.$nextTick(() => this.autoGrowTextarea());
        };
        
        this.recognition.onend = () => { 
          this.isListening = false; 
        };
        
        this.recognition.onerror = (event) => {
          this.isListening = false;
          if (event.error === 'not-allowed') {
            this.error = "Microphone access was denied. Please allow access in your browser settings.";
          } else if (event.error === 'no-speech'){
            console.info("No speech detected");
          } else {
            this.error = `Speech recognition error: ${event.error}`;
            console.error("Speech Recognition Error", event);
          }
        };
      } else {
        console.warn("Speech recognition not supported in this browser.");
        this.recognition = null;
      }
    },
    
    submitOnEnter(event) {
      if (!event.shiftKey) {
        event.preventDefault();
        this.submitPrompt();
      }
    },
    
    addLineBreak() {
      this.$nextTick(() => this.autoGrowTextarea());
    },
    
    autoGrowTextarea() {
      const el = this.$refs.promptInput;
      if (el) {
        el.style.height = 'auto';
        const newHeight = Math.min(el.scrollHeight, 150);
        el.style.height = `${newHeight}px`;
      }
    },
    
    async submitPrompt() {
      const promptText = this.userPrompt.trim();
      if (!promptText || this.isLoading) return;

      this.isLoading = true;
      this.error = null;
      this.userPrompt = '';
      this.$nextTick(() => this.autoGrowTextarea());

      const userMessageId = `user-${Date.now()}`;
      this.conversationHistory.push({
        role: 'user',
        prompt: promptText,
        timestamp: new Date().toISOString(),
        id: userMessageId
      });

      const aiMessageIndex = this.conversationHistory.length;
      const aiPlaceholderId = `ai-pending-${Date.now()}`;
      this.conversationHistory.push({
        role: 'ai',
        response: '...',
        timestamp: new Date().toISOString(),
        id: aiPlaceholderId,
        isLoading: true
      });
      
      this.scrollToBottom();

      try {
        const result = await aiService.getAssistantResponse(promptText, this.selectedPersona);
        if (result && result.reply && result.id) {
          this.conversationHistory[aiMessageIndex].response = result.reply;
          this.conversationHistory[aiMessageIndex].timestamp = result.timestamp || new Date().toISOString();
          this.conversationHistory[aiMessageIndex].isLoading = false;
          this.conversationHistory[aiMessageIndex].id = result.id;
        } else {
          console.error('Backend response issue: Result missing id or reply:', result);
          this.conversationHistory[aiMessageIndex].response = 'Error: Received incomplete response from server.';
          this.conversationHistory[aiMessageIndex].timestamp = new Date().toISOString();
          this.conversationHistory[aiMessageIndex].isLoading = false;
        }
      } catch (err) {
        console.error('AI Assistant error:', err);
        this.error = err.response?.data?.message || 'Failed to get response from AI Assistant.';
        this.conversationHistory.splice(aiMessageIndex, 1);
      } finally {
        this.isLoading = false;
        this.scrollToBottom();
        this.$nextTick(() => this.$refs.promptInput?.focus());
      }
    },
    
    async generateReport() {
      if (this.isLoading || this.isStreaming) return;
      this.isLoading = true;
      this.error = null;

      const userActionId = `user-action-${Date.now()}`;
      this.conversationHistory.push({
        role: 'user',
        prompt: "Requesting team performance report generation...",
        timestamp: new Date().toISOString(),
        id: userActionId
      });
      
      const aiMessageIndex = this.conversationHistory.length;
      this.conversationHistory.push({
        role: 'ai',
        response: 'Generating report, please wait...',
        timestamp: new Date().toISOString(),
        id: `ai-pending-${Date.now()}`
      });

      try {
        const result = await aiService.generateReport(this.selectedPersona);
        if (result && result.reply) {
          this.conversationHistory[aiMessageIndex].response = result.reply;
          this.conversationHistory[aiMessageIndex].id = result.id || `ai-report-${Date.now()}`;
          this.conversationHistory[aiMessageIndex].timestamp = result.timestamp || new Date().toISOString();
        } else {
          throw new Error('Invalid response format');
        }
      } catch (err) {
        console.error('Report generation error:', err);
        this.error = err.message || 'Failed to generate report.';
        this.conversationHistory[aiMessageIndex].response = 'Sorry, the report could not be generated at this time.';
      } finally {
        this.isLoading = false;
        this.scrollToBottom();
      }
    },
    
    async rateResponse(responseId, rating) {
      const index = this.conversationHistory.findIndex(item => item.id === responseId);
      if (index === -1) return;
      
      this.conversationHistory[index].feedbackGiven = true;
      
      try {
        await aiService.submitFeedback(responseId, rating);
      } catch (err) {
        console.error('Feedback submission error:', err);
        this.error = err.response?.data?.message || 'Failed to submit feedback.';
        this.conversationHistory[index].feedbackGiven = false;
      }
    },
    
    formatTimestamp(timestamp) {
      if (!timestamp) return '';
      try {
        const date = new Date(timestamp);
        const today = new Date();
        if (date.toDateString() === today.toDateString()) {
          return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        }
        return date.toLocaleString([], { 
          month: 'short', 
          day: 'numeric',
          hour: 'numeric', 
          minute: '2-digit'
        });
      } catch (e) { 
        console.error('Error formatting timestamp:', e);
        return 'Invalid Date'; 
      }
    },
    
    formatMarkdown(text) {
      if (!text) return '';
      try {
        marked.setOptions({
          breaks: true,
          gfm: true,
          headerIds: false,
          mangle: false
        });
        return DOMPurify.sanitize(marked.parse(text), {
          ALLOWED_TAGS: [
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'p', 'a', 'ul', 'ol',
            'nl', 'li', 'b', 'i', 'strong', 'em', 'strike', 'code', 'hr', 'br', 'div',
            'table', 'thead', 'caption', 'tbody', 'tr', 'th', 'td', 'pre', 'img', 'span'
          ],
          ALLOWED_ATTR: ['href', 'name', 'target', 'class', 'id', 'style', 'src', 'alt']
        });
      } catch (e) {
        console.error('Error formatting markdown:', e);
        return text;
      }
    },
    
    scrollToBottom() {
      this.$nextTick(() => {
        const container = this.$refs.conversationContainer;
        if (container) {
          container.scrollTo({
            top: container.scrollHeight,
            behavior: 'smooth'
          });
        }
      });
    },
    
    saveChat() {
      if (this.conversationHistory.length === 0) return;
      try {
        const dateStr = new Date()
          .toISOString()
          .slice(0, 19)
          .replace(/[T:]/g, '-');
        const filename = `modzee-ai-chat-${dateStr}.json`;
        const chatData = {
          version: '1.0',
          persona: this.selectedPersona,
          savedAt: new Date().toISOString(),
          history: this.conversationHistory.map(item => ({
            ...item,
            isLoading: undefined
          }))
        };
        const jsonStr = JSON.stringify(chatData, null, 2);
        const blob = new Blob([jsonStr], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        setTimeout(() => {
          document.body.removeChild(link);
          URL.revokeObjectURL(url);
        }, 100);
      } catch (err) {
        console.error("Failed to save chat:", err);
        this.error = "Could not save the chat history.";
      }
    },
    
    clearConversation() {
      if (this.conversationHistory.length === 0) return;
      if (window.confirm('Are you sure you want to clear this conversation? This action cannot be undone.')) {
        this.conversationHistory = [];
        localStorage.removeItem('aiConversationHistory');
        this.error = null;
      }
    },
    
    dismissError() {
      this.error = null;
    }
  }
};
</script>
<style scoped>
/*––––– BASE CSS VARIABLES –––––*/
:root {
  /* Keep base colors/values if needed globally, or remove if only used here */
  --primary-color: #FF6B6B;
  --primary-dark: #E05555;
  --primary-light: #FFB2B2;
  --success-color: #52c41a;
  --warning-color: #faad14;
  --error-color: #D93026; /* Used for error messages */
  --error-text-light: #a8071a; /* Text color for light error bg */
  --error-text-dark: #ffccc7;  /* Text color for dark error bg */

  /* Non-theme specific layout values */
  --border-radius-main: 12px;
  --border-radius-small: 6px;
  --header-height: 65px;
  --transition-speed: 0.25s;
  --shadow-main: 0 8px 25px rgba(0, 0, 0, 0.1);
  --shadow-hover: 0 12px 35px rgba(0, 0, 0, 0.15);
}

/*––––– THEME DEFINITIONS –––––*/
.ai-assistant-container {
  /* Default to Light Mode Variables */
  --gradient-start: #FF6B6B;
  --gradient-end: #FFD3B6;
  --container-bg: #FFFFFF;
  --text-primary: #333333;
  --text-secondary: #666666;
  --text-tertiary: #999999;
  --text-on-primary: #FFFFFF; /* Text on primary buttons */
  --input-border: #E0CFCF;
  --input-bg: #F7EAEA;
  --user-message-bg: linear-gradient(135deg, #FFEAEF, #FFD9E1);
  --ai-message-bg: linear-gradient(135deg, #FFF5E9, #FFECD9);
  --error-bg: #FFF1F0;
  --error-text: var(--error-text-light);
  --header-bg: rgba(255, 255, 255, 0.98);
  --footer-bg: #f9f9f9;
  --scroll-track: #f1f1f1;
  --scroll-thumb: #ccc;
  --scroll-thumb-hover: #aaa;
  --conversation-bg: #f3e8e3;
  --button-secondary-bg: #f0f0f0;
  --button-secondary-border: #ddd;
  --button-secondary-hover-bg: #e5e5e5;
  --button-secondary-hover-border: #ccc;
  --markdown-code-bg: #eee;
  --markdown-pre-bg: #f5f5f5;
  --markdown-pre-border: #eee;
  --markdown-blockquote-border: #ccc;
  --markdown-blockquote-color: #666;
  --typing-indicator-color: var(--primary-color);
  --feedback-given-color: var(--success-color);
  --input-placeholder-color: #aaa;
  --input-action-btn-hover-bg: #f0f0f0;
}

.ai-assistant-container.dark-mode {
  /* Override with Dark Mode Variables */
  --gradient-start: #1d1d1d;
  --gradient-end: #2a2a2a;
  --container-bg: #242424;
  --text-primary: #F0F0F0;
  --text-secondary: #CCCCCC;
  --text-tertiary: #AAAAAA;
  /* --text-on-primary: #FFFFFF; */ /* Usually same, uncomment if needed */
  --input-border: #555555;
  --input-bg: #2a2a2a;
  --user-message-bg: linear-gradient(135deg, #2e2e2e, #3a3a3a);
  --ai-message-bg: linear-gradient(135deg, #303030, #3e3e3e);
  --error-bg: #4d0000;
  --error-text: var(--error-text-dark);
  --header-bg: rgba(36, 36, 36, 0.98);
  --footer-bg: #2a2a2a;
  --scroll-track: #333333;
  --scroll-thumb: #555555;
  --scroll-thumb-hover: #777777;
  --conversation-bg: #2b2b2b;
  --button-secondary-bg: #3a3a3a;
  --button-secondary-border: #555;
  --button-secondary-hover-bg: #4a4a4a;
  --button-secondary-hover-border: #666;
  --markdown-code-bg: #383838;
  --markdown-pre-bg: #2f2f2f;
  --markdown-pre-border: #444;
  --markdown-blockquote-border: #666;
  --markdown-blockquote-color: #bbb;
  --typing-indicator-color: var(--primary-light); /* Adjust for better contrast */
  --feedback-given-color: #a0d911; /* Lighter green for dark mode */
  --input-placeholder-color: #777;
  --input-action-btn-hover-bg: #444;
}

/* Remove the previous redundant theme context sections */
/* .ai-assistant-container.light-mode { ... } */
/* .ai-assistant-container.dark-mode { ... } */
/* These are no longer needed as the variables are defined above */


/*––––– ANIMATIONS –––––*/
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes pulse {
  0%, 100% { transform: scale(1); opacity: 0.95; }
  50% { transform: scale(1.1); opacity: 1; }
}
/* Adjusted typing animation keyframes name for clarity */
@keyframes typingDots {
  0%, 80%, 100% { transform: scale(0); }
  40% { transform: scale(1.4); } /* Adjusted scale for visibility */
}

/* Vue transition classes */
.fade-enter-active, .fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from, .fade-leave-to {
  opacity: 0;
}
.message-enter-active {
  transition: all 0.3s ease;
}
.message-enter-from {
  opacity: 0;
  transform: translateY(20px);
}
.message-move {
  transition: transform 0.5s ease;
}

/*––––– GLOBAL STYLES –––––*/
.ai-assistant-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  padding: 20px;
  background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  transition: background var(--transition-speed) ease; /* Smooth background transition */
}
.ai-assistant {
  width: 100%;
  max-width: 800px;
  height: calc(100vh - 40px);
  max-height: 900px;
  background-color: var(--container-bg);
  color: var(--text-primary); /* Set base text color */
  border-radius: var(--border-radius-main);
  box-shadow: var(--shadow-main);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: background-color var(--transition-speed) ease, box-shadow var(--transition-speed) ease, color var(--transition-speed) ease;
}
.ai-assistant:hover {
  box-shadow: var(--shadow-hover);
}

/*––––– HEADER –––––*/
.assistant-header {
  display: flex;
  justify-content: space-between; /* Better alignment */
  align-items: center;
  padding: 0 20px;
  height: var(--header-height);
  border-bottom: 1px solid var(--input-border); /* Use theme variable */
  background-color: var(--header-bg);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  flex-shrink: 0;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  transition: background-color var(--transition-speed) ease, border-color var(--transition-speed) ease;
}
.header-left { /* Group logo and title */
  display: flex;
  align-items: center;
}
.logo {
  height: 32px;
  margin-right: 15px;
  /* Consider adding filter for dark mode if needed: filter: brightness(0) invert(1); */
}
.assistant-header h2 {
  margin: 0;
  font-size: 1.5em;
  color: var(--text-primary);
  font-weight: 600;
  transition: color var(--transition-speed) ease;
}
.header-controls {
  /* Removed margin-left: auto; covered by justify-content */
  display: flex;
  align-items: center;
}
.persona-select {
  padding: 5px 10px;
  border: 1px solid var(--input-border);
  border-radius: var(--border-radius-small);
  background-color: var(--input-bg);
  color: var(--text-primary); /* Inherit text color */
  font-size: 0.9em;
  cursor: pointer;
  transition: border-color var(--transition-speed) ease, background-color var(--transition-speed) ease, color var(--transition-speed) ease;
  margin-right: 10px;
  -webkit-appearance: none; /* Basic styling reset */
  -moz-appearance: none;
  appearance: none;
  /* Add dropdown arrow */
  background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23cccccc%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.4-5.4-12.8z%22%2F%3E%3C%2Fsvg%3E');
  background-repeat: no-repeat;
  background-position: right 10px top 50%;
  background-size: .65em auto;
  padding-right: 30px; /* Space for arrow */
}
.dark-mode .persona-select {
  /* Dark mode arrow color */
  background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23555555%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.4-5.4-12.8z%22%2F%3E%3C%2Fsvg%3E');
}
.persona-select:focus {
  border-color: var(--primary-color);
  outline: none;
  box-shadow: 0 0 0 2px rgba(255, 107, 107, 0.3); /* Focus ring */
}

/*––––– THEME TOGGLE BUTTON –––––*/
.theme-toggle {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.5em;
  transition: transform var(--transition-speed) ease;
  color: var(--text-secondary); /* Color for emoji */
  line-height: 1; /* Ensure consistent vertical alignment */
  padding: 4px; /* Add some clickable area */
  margin-left: 5px; /* Spacing */
}
.theme-toggle:hover {
  transform: scale(1.1);
}

/*––––– CONVERSATION AREA –––––*/
.conversation-area {
  flex-grow: 1;
  overflow-y: auto;
  padding: 20px;
  display: flex;
  flex-direction: column;
  background-color: var(--conversation-bg);
  transition: background-color var(--transition-speed) ease;
}
.conversation-history {
  display: flex;
  flex-direction: column;
  gap: 15px;
}
.conversation-item {
  display: flex;
  max-width: 85%; /* Slightly wider */
  animation: fadeIn 0.4s ease-out;
  /* Removed hover transform for stability */
}
.conversation-item.with-animation { /* Apply animation only after initial load */
  animation: fadeIn 0.4s ease-out;
}
.user-message {
  align-self: flex-end;
}
.ai-message {
  align-self: flex-start;
}
.message-bubble {
  padding: 12px 18px;
  border-radius: var(--border-radius-main);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  word-wrap: break-word;
  position: relative;
  transition: background var(--transition-speed) ease, box-shadow var(--transition-speed) ease, color var(--transition-speed) ease;
  color: var(--text-primary); /* Ensure bubble text color adapts */
}
.user-message .message-bubble {
  background: var(--user-message-bg);
  border-bottom-right-radius: 4px;
}
.ai-message .message-bubble {
  background: var(--ai-message-bg);
  border-bottom-left-radius: 4px;
}
.message-sender {
  display: block;
  font-size: 0.85em;
  font-weight: 600;
  margin-bottom: 5px;
  color: var(--text-secondary); /* Use secondary text color */
  transition: color var(--transition-speed) ease;
}
.user-message .message-sender { color: var(--primary-dark); } /* Use darker primary */
.ai-message .message-sender { color: var(--success-color); } /* Use success color */
.dark-mode .ai-message .message-sender { color: #a0d911; } /* Lighter green for dark */

.message-content {
  font-size: 0.95em;
  line-height: 1.6;
  white-space: pre-wrap; /* Keep this for user messages */
}
.markdown-content {
  white-space: normal; /* Override pre-wrap for markdown */
}
.markdown-content > *:first-child { margin-top: 0; }
.markdown-content > *:last-child { margin-bottom: 0; }
.markdown-content p { margin-bottom: 0.8em; }
.markdown-content h1,
.markdown-content h2,
.markdown-content h3 { margin: 1em 0 0.5em; line-height: 1.3; }
.markdown-content ul,
.markdown-content ol { padding-left: 20px; margin-bottom: 0.8em; }
.markdown-content li { margin-bottom: 0.3em; }
.markdown-content blockquote {
  border-left: 3px solid var(--markdown-blockquote-border);
  padding-left: 10px;
  margin: 0.5em 0;
  color: var(--markdown-blockquote-color);
  font-style: italic;
  transition: color var(--transition-speed) ease, border-color var(--transition-speed) ease;
}
.markdown-content pre {
  background-color: var(--markdown-pre-bg);
  padding: 10px;
  border-radius: var(--border-radius-small);
  overflow-x: auto;
  border: 1px solid var(--markdown-pre-border);
  font-size: 0.9em;
  margin: 0.5em 0;
  color: var(--text-primary); /* Code text color */
  transition: background-color var(--transition-speed) ease, border-color var(--transition-speed) ease, color var(--transition-speed) ease;
}
.markdown-content code:not(pre code) {
  background-color: var(--markdown-code-bg);
  color: var(--text-primary); /* Inline code text color */
  padding: 2px 5px;
  border-radius: 3px;
  font-size: 0.9em;
  transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease;
}
.message-meta {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  margin-top: 8px;
  font-size: 0.75em;
  color: var(--text-tertiary); /* Use tertiary text color */
  gap: 8px;
  transition: color var(--transition-speed) ease;
}
.timestamp {
  opacity: 0.8; /* Slightly more visible */
}
.feedback-buttons {
  display: flex;
  gap: 4px;
}
.feedback-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px;
  font-size: 1.1em;
  opacity: 0.7;
  transition: opacity var(--transition-speed) ease, transform 0.1s ease;
}
.feedback-btn:hover {
  opacity: 1;
  transform: scale(1.1);
}
.feedback-btn:active {
  transform: scale(0.95);
}
.feedback-given {
  font-size: 1.1em;
  color: var(--feedback-given-color);
  transition: color var(--transition-speed) ease;
}

/*––––– LOADING INDICATOR & TYPING ANIMATION –––––*/
.loading-indicator-container .message-bubble {
  background: var(--ai-message-bg);
  padding-top: 20px; /* Keep padding consistent */
  padding-bottom: 20px;
}
.typing-indicator {
  display: flex;
  gap: 6px;
  align-items: center;
  height: 20px; /* Matches bubble padding */
}
.typing-indicator span {
  width: 10px;
  height: 10px;
  background-color: var(--typing-indicator-color); /* Use variable */
  border-radius: 50%;
  animation: typingDots 1.2s infinite ease-in-out both; /* Use 'both' fill mode */
  transition: background-color var(--transition-speed) ease;
}
.typing-indicator span:nth-child(1) { animation-delay: 0s; }
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
/* Keyframes already defined above */


/*––––– EMPTY STATE –––––*/
.empty-state {
  text-align: center;
  color: var(--text-secondary);
  margin: auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px;
  transition: color var(--transition-speed) ease;
}
.empty-state-icon {
  font-size: 2.8em;
  margin-bottom: 15px;
  opacity: 0.9;
  animation: pulse 2s infinite;
}
.empty-state p {
  font-size: 1.15em;
  color: var(--text-primary);
  margin-bottom: 5px;
  transition: color var(--transition-speed) ease;
}
.empty-state span {
  font-size: 1em;
}

/*––––– FOOTER & INPUT AREA –––––*/
.input-area {
  padding: 15px 20px;
  border-top: 1px solid var(--input-border); /* Use theme variable */
  background-color: var(--footer-bg);
  flex-shrink: 0;
  transition: background-color var(--transition-speed) ease, border-color var(--transition-speed) ease;
}
.error-message {
  background-color: var(--error-bg);
  color: var(--error-text); /* Use theme variable */
  padding: 8px 12px;
  border-radius: var(--border-radius-small);
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.9em;
  transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease;
}
.dismiss-btn {
  background: none;
  border: none;
  color: var(--error-text); /* Use theme variable */
  font-size: 1.2em;
  cursor: pointer;
  padding: 0 5px;
  opacity: 0.8;
  transition: opacity 0.2s ease, color var(--transition-speed) ease;
}
.dismiss-btn:hover {
  opacity: 1;
}

/*––––– INPUT WRAPPER & BUTTONS –––––*/
.input-wrapper {
  display: flex;
  align-items: flex-end;
  background-color: var(--input-bg);
  border: 1px solid var(--input-border);
  border-radius: var(--border-radius-main);
  padding: 5px;
  margin-bottom: 10px;
  transition: border-color var(--transition-speed) ease, box-shadow var(--transition-speed) ease, background-color var(--transition-speed) ease;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); /* Softer shadow */
}
.input-wrapper:focus-within {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.35); /* Adjusted alpha */
}
textarea {
  flex-grow: 1;
  border: none;
  outline: none;
  padding: 10px;
  font-size: 1em;
  line-height: 1.5;
  resize: none;
  background: transparent;
  max-height: 150px; /* Consistent max height */
  min-height: 40px; /* Ensure minimum height matches button */
  overflow-y: auto; /* Changed from scroll */
  color: var(--text-primary);
  transition: color var(--transition-speed) ease;
}
textarea::placeholder {
  color: var(--input-placeholder-color); /* Use variable */
  transition: color var(--transition-speed) ease;
}
.input-action-btn {
  background: none;
  border: none;
  padding: 8px;
  margin-left: 5px;
  cursor: pointer;
  color: var(--text-secondary);
  font-size: 1.3em;
  line-height: 1;
  border-radius: 50%; /* Make circular */
  width: 36px; /* Explicit size */
  height: 36px; /* Explicit size */
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0; /* Prevent shrinking */
  transition: background-color var(--transition-speed), color var(--transition-speed), transform 0.1s ease;
}
.input-action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.input-action-btn:not(:disabled):hover {
  background-color: var(--input-action-btn-hover-bg); /* Use variable */
}
.input-action-btn:not(:disabled):active {
  transform: scale(0.9); /* Click feedback */
}
.voice-btn.listening {
  color: var(--primary-color);
  background-color: rgba(255, 107, 107, 0.1); /* Subtle background when listening */
}
.submit-btn {
  background-color: var(--primary-color);
  color: var(--text-on-primary);
  border-radius: var(--border-radius-main); /* Match wrapper */
  width: auto; /* Allow width to adjust */
  height: 36px; /* Match other buttons */
  padding: 0 12px; /* Horizontal padding */
  font-size: 1.2em; /* Slightly adjust icon size */
  transition: background-color var(--transition-speed), transform 0.1s ease;
}
.submit-btn:not(:disabled):hover {
  background-color: var(--primary-dark);
}
.submit-btn:not(:disabled):active {
  transform: scale(0.95); /* Click feedback */
}
.submit-btn span { /* Ensure icon/text inside aligns well */
  display: inline-block;
  line-height: 1;
}
.action-buttons {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: flex-start; /* Align buttons left */
  margin-top: 5px; /* Add space above buttons */
}
.secondary-action-btn {
  padding: 6px 12px;
  background-color: var(--button-secondary-bg); /* Use variable */
  border: 1px solid var(--button-secondary-border); /* Use variable */
  color: var(--text-secondary);
  border-radius: var(--border-radius-small);
  font-size: 0.85em;
  cursor: pointer;
  transition: background-color var(--transition-speed), border-color var(--transition-speed), color var(--transition-speed), transform 0.1s ease;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.secondary-action-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.secondary-action-btn:not(:disabled):hover {
  background-color: var(--button-secondary-hover-bg); /* Use variable */
  border-color: var(--button-secondary-hover-border); /* Use variable */
  color: var(--text-primary); /* Slightly darken text on hover */
}
.secondary-action-btn:not(:disabled):active {
  transform: scale(0.98); /* Subtle click feedback */
}
.secondary-action-btn.clear-btn:not(:disabled):hover {
  background-color: rgba(217, 48, 38, 0.1); /* Light error bg on hover */
  border-color: rgba(217, 48, 38, 0.5);
  color: var(--error-color); /* Error color text */
}
.dark-mode .secondary-action-btn.clear-btn:not(:disabled):hover {
  background-color: rgba(255, 77, 79, 0.15); /* Darker error bg on hover */
  border-color: rgba(255, 77, 79, 0.6);
  color: #ff7875; /* Lighter error color */
}

/*––––– CUSTOM SCROLLBAR –––––*/
/* Works in WebKit/Blink browsers (Chrome, Safari, Edge, Opera) */
.conversation-area::-webkit-scrollbar,
textarea::-webkit-scrollbar {
  width: 8px; /* Slightly wider scrollbar */
}
.conversation-area::-webkit-scrollbar-track,
textarea::-webkit-scrollbar-track {
  background: var(--scroll-track);
  border-radius: 4px;
}
.conversation-area::-webkit-scrollbar-thumb,
textarea::-webkit-scrollbar-thumb {
  background: var(--scroll-thumb);
  border-radius: 4px;
  border: 2px solid var(--scroll-track); /* Creates padding around thumb */
}
.conversation-area::-webkit-scrollbar-thumb:hover,
textarea::-webkit-scrollbar-thumb:hover {
  background: var(--scroll-thumb-hover);
}

/* Firefox scrollbar styling */
.conversation-area, textarea {
  scrollbar-width: thin; /* "auto" or "thin" */
  scrollbar-color: var(--scroll-thumb) var(--scroll-track); /* thumb track */
}

</style>