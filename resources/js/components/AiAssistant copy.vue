<template>
  <div class="ai-assistant-container">
    <div class="ai-assistant">
      <header class="assistant-header">
        <img src="/public/cg-logo.png" alt="Modzee Logo" class="logo" /> <h2>AI Assistant</h2>
        <div class="header-controls">
            <select id="persona" v-model="selectedPersona" class="persona-select" title="Select Assistant Persona">
                <option v-for="persona in personas" :key="persona.value" :value="persona.value">
                  {{ persona.label }}
                </option>
            </select>
            </div>
      </header>

      <div class="conversation-area" ref="conversationContainer">
        <div v-if="conversationHistory.length === 0" class="empty-state">
            <div class="empty-state-icon">✨</div>
            <p>Ready to assist!</p>
            <span>Ask anything or try generating a report.</span>
             </div>

        <div v-else class="conversation-history">
          <div
            v-for="(item, index) in conversationHistory"
            :key="item.id || index"
            class="conversation-item"
            :class="item.role === 'user' ? 'user-message' : 'ai-message'"
          >
            <div class="message-bubble">
               <strong class="message-sender">{{ item.role === 'user' ? 'You' : 'AI Assistant' }}</strong>
               <div v-if="item.role === 'user'" v-text="item.prompt" class="message-content"></div>
               <!-- <div v-else v-html="formatMarkdown(item.response)" class="message-content markdown-content"></div> -->
               <div v-else class="message-content markdown-content">
                  {{ item.response }}
            </div>
               <div class="message-meta">
                  <small class="timestamp">{{ formatTimestamp(item.timestamp) }}</small>
                  <div class="feedback-buttons" v-if="item.role === 'ai' && item.id && !item.feedbackGiven">
                     <button @click="rateResponse(item.id, 'helpful')" class="feedback-btn helpful" title="Helpful">👍</button>
                     <button @click="rateResponse(item.id, 'not_helpful')" class="feedback-btn not-helpful" title="Not Helpful">👎</button>
                   </div>
                   <div v-else-if="item.role === 'ai' && item.feedbackGiven" class="feedback-given" title="Feedback submitted">✔️</div>
               </div>
            </div>
          </div>
           <div v-if="isStreaming || isLoading" class="conversation-item ai-message loading-indicator-container">
               <div class="message-bubble">
                    <strong class="message-sender">AI Assistant</strong>
                    <div class="typing-indicator">
                       <span></span><span></span><span></span>
                    </div>
               </div>
            </div>
        </div>
         </div>

      <footer class="input-area">
         <div v-if="error" class="error-message">
             <p>{{ error }}</p>
             <button @click="dismissError" class="dismiss-btn" title="Dismiss Error">×</button>
         </div>
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
            ></textarea>
             <button
                v-if="recognition && !isListening"
                @click="startVoiceInput"
                class="input-action-btn voice-btn"
                :disabled="isLoading || isStreaming"
                title="Start Voice Input"
             >🎤</button>
              <button
                v-if="recognition && isListening"
                @click="startVoiceInput"
                class="input-action-btn voice-btn listening"
                :disabled="isLoading || isStreaming"
                title="Stop Listening"
             >⏹️</button>
            <button
              @click="submitPrompt"
              :disabled="isLoading || isStreaming || !userPrompt.trim()"
              class="input-action-btn submit-btn"
              title="Send Message (Enter)"
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
            >
              📄 Generate Report
            </button>
             <button
                @click="saveChat"
                :disabled="isLoading || isStreaming || conversationHistory.length === 0"
                class="secondary-action-btn"
                title="Save current conversation to a file"
             >
              💾 Save Chat
            </button>
            <button
              @click="clearConversation"
              :disabled="isLoading || isStreaming || conversationHistory.length === 0"
              class="secondary-action-btn clear-btn"
               title="Clear current conversation"
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
import aiService from '../services/aiService'; // Adjust path if needed

// Optional: Configure marked
// marked.setOptions({ breaks: true /* other options */ });

export default {
  name: 'AiAssistant',
  // components: {}, // Add if using child components like AiChart

  data() {
    return {
      userPrompt: '',
      isLoading: false, // General loading state (e.g., for report generation)
      isStreaming: false, // Specifically for AI response streaming
      isListening: false, // For voice input
      error: null,
      selectedPersona: 'general',
      conversationHistory: [], // Will store objects like { role: 'user'/'ai', prompt: '...', response: '...', timestamp: '...', id: '...' }
      // Removed usageStats/History for cleaner UI - can be added back
      recognition: null,
      isAuthenticated: true, // Assume authenticated for demo; fetch dynamically
       personas: [
        { label: 'General Assistant', value: 'general' },
        { label: 'Sales Analyst', value: 'sales' },
        { label: 'HR Advisor', value: 'hr' },
        { label: 'Technical Advisor', value: 'technical' }
      ],
    };
  },

  computed: {
    // Removed usage computed properties - add back if needed
    previousMessages() {
      // Format history for API context, excluding feedback/internal properties
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
        this.scrollToBottom(); // Scroll whenever history changes
      },
      deep: true
    },
    selectedPersona(newValue) {
      localStorage.setItem('aiSelectedPersona', newValue);
      // Optional: Clear chat or notify user if persona change affects context
    }
  },

  mounted() {
    // Check auth status
    // const authMeta = document.querySelector('meta[name="is-authenticated"]');
    // this.isAuthenticated = authMeta && authMeta.content === 'true';

    this.loadConversation();
    this.initSpeechRecognition();

    // Fetch initial data if needed (e.g., usage)
    // if (this.isAuthenticated) { this.fetchUsageStats(); }

     this.$nextTick(() => {
        this.autoGrowTextarea(); // Adjust textarea height on load
     });
  },

  methods: {
    loadConversation() {
        const savedHistory = localStorage.getItem('aiConversationHistory');
        const savedPersona = localStorage.getItem('aiSelectedPersona');
        if (savedHistory) {
            try {
                const parsedHistory = JSON.parse(savedHistory);
                // Basic validation - ensure it's an array
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
             // Validate saved persona against available options
            if (this.personas.some(p => p.value === savedPersona)) {
                this.selectedPersona = savedPersona;
            } else {
                 localStorage.removeItem('aiSelectedPersona'); // Remove invalid value
            }
        }
         this.scrollToBottom(); // Scroll after loading
    },

    initSpeechRecognition() {
        if ('webkitSpeechRecognition' in window) {
            this.recognition = new webkitSpeechRecognition();
            this.recognition.continuous = false;
            this.recognition.interimResults = false;
            this.recognition.lang = 'en-US';

            this.recognition.onresult = (event) => {
                this.userPrompt = event.results[0][0].transcript;
                this.isListening = false;
                this.$nextTick(() => this.autoGrowTextarea()); // Adjust height after adding text
            };
            this.recognition.onend = () => { this.isListening = false; };
            this.recognition.onerror = (event) => {
                this.isListening = false;
                this.error = `Speech recognition error: ${event.error}`;
                console.error("Speech Recognition Error", event);
            };
        } else {
            console.warn("Speech recognition not supported.");
            this.recognition = null; // Ensure it's null if not supported
        }
    },

    submitOnEnter(event) {
        // Prevent Shift+Enter from submitting
        if (!event.shiftKey) {
            event.preventDefault(); // Prevent default newline
            this.submitPrompt();
        }
    },

    addLineBreak() {
        // This method is triggered by Shift+Enter but we just let the default behavior happen (add newline)
        // We might need this if we manually handle textarea content insertion
        this.$nextTick(() => this.autoGrowTextarea()); // Ensure height adjusts
    },

    autoGrowTextarea() {
      const el = this.$refs.promptInput;
      if (el) {
        el.style.height = 'auto'; // Reset height
        el.style.height = (el.scrollHeight) + 'px'; // Set to scroll height
      }
    },

    async submitPrompt() {
  const promptText = this.userPrompt.trim();
  // Use isLoading for the non-streaming request required by the PDF
  if (!promptText || this.isLoading) return; // Keep guards

  this.isLoading = true; // Use a single loading state indicator
  this.error = null;
  this.userPrompt = '';
  this.$nextTick(() => this.autoGrowTextarea());

  // Add user message
  this.conversationHistory.push({
    role: 'user',
    prompt: promptText,
    response: null,
    timestamp: new Date().toISOString(),
    id: `user-${Date.now()}`
  });

  // Add placeholder for AI response while waiting
  const aiMessageIndex = this.conversationHistory.length;
  this.conversationHistory.push({
    role: 'ai',
    prompt: null,
    response: '...', // Waiting indicator
    timestamp: new Date().toISOString(), // Placeholder
    id: `ai-pending-${Date.now()}`,
    isLoading: true // Flag for styling placeholder
  });
  this.scrollToBottom();

  try {
    // *** THIS IS THE KEY CHANGE ***
    // Call the updated service function targeting /api/ai/assistant
    const result = await aiService.getAssistantResponse(promptText); // Use getAssistantResponse

    console.log('RAW API RESULT RECEIVED:', result); // **** ADD THIS LINE ***
    // console.log('API Response:', result); // Debugging log
    // console.log('AI Assistant Response:', result.reply); // Debugging log

  
    console.log('API Result:', result);
    console.log('Extracted Reply:', result.reply);
    console.log('Target Index:', aiMessageIndex);
    console.log('Object before update:', this.conversationHistory[aiMessageIndex]);

    // Check if the necessary data is present
    if (result && result.reply && result.id) {
      console.log('BACKEND SUCCESS: Storing DB ID:', result.id, 'for index:', aiMessageIndex); // Verify ID received

        console.log('Extracted Reply:', result.reply, 'Extracted ID:', result.id); // Check received ID

        console.log('Updating index:', aiMessageIndex, 'Current object:', this.conversationHistory[aiMessageIndex]);

        // Update the conversation history item using direct assignment (Vue 3)
        this.conversationHistory[aiMessageIndex].response = result.reply;
        this.conversationHistory[aiMessageIndex].timestamp = result.timestamp || new Date().toISOString();
        this.conversationHistory[aiMessageIndex].isLoading = false;
        this.conversationHistory[aiMessageIndex].id = result.id; // *** STORE THE ID ***

        console.log('Object after update:', this.conversationHistory[aiMessageIndex]);
    } else {
      console.error('BACKEND RESPONSE ISSUE: Result missing id or reply:', result);
        // Handle cases where the response might be missing expected fields
        console.error('Received incomplete data from backend:', result);
        // Update state to show an error or default message
        this.conversationHistory[aiMessageIndex].response = 'Error: Received incomplete response from server.';
        this.conversationHistory[aiMessageIndex].timestamp = new Date().toISOString();
        this.conversationHistory[aiMessageIndex].isLoading = false;
        // Keep a placeholder or null ID if backend didn't provide one
        this.conversationHistory[aiMessageIndex].id = null;
    }

    console.log('Object after update:', this.conversationHistory[aiMessageIndex]);

    // console.log('Object after update:', this.conversationHistory[aiMessageIndex]); // Debugging log

  } catch (err) {
    console.error('AI Assistant error:', err); // Keep console logging for debugging
    this.error = err.response?.data?.message || 'Failed to get response from AI Assistant.';
    // Remove the AI placeholder on failure
    this.conversationHistory.splice(aiMessageIndex, 1);
  } finally {
    this.isLoading = false; // Reset loading state
    this.scrollToBottom();
    this.$nextTick(() => this.$refs.promptInput?.focus());
  }
},

    async generateReport() {
      if (this.isLoading || this.isStreaming) return;
      this.isLoading = true;
      this.error = null;

      // Add placeholder messages
       this.conversationHistory.push({
          role: 'user', // Represent the action as initiated by user
          prompt: "Requesting team performance report generation...",
          response: null,
          timestamp: new Date().toISOString(),
          id: `user-action-${Date.now()}`
      });
       const aiMessageIndex = this.conversationHistory.length;
       this.conversationHistory.push({
           role: 'ai',
           prompt: null,
           response: 'Generating report, please wait...', // Initial status
           timestamp: new Date().toISOString(),
           id: `ai-pending-${Date.now()}`
       });


      try {
        // No streaming indicator needed here as isLoading handles it globally
        const result = await aiService.generateReport(); // Assume this returns { id, response, timestamp }

         // Update AI message with report content
         
         this.conversationHistory[aiMessageIndex].response = result.reply;
         this.conversationHistory[aiMessageIndex].id  = result.id; // Store the ID received from the backend
         this.conversationHistory[aiMessageIndex].timestamp = result.timestamp || new Date().toISOString();

        //  this.$set(this.conversationHistory[aiMessageIndex], 'id', result.id);
        //  this.$set(this.conversationHistory[aiMessageIndex], 'response', result.response);
        //  this.$set(this.conversationHistory[aiMessageIndex], 'timestamp', result.timestamp || new Date().toISOString());

        // Optional: Update usage stats
        // if (this.isAuthenticated) { this.fetchUsageStats(); }

      } catch (err) {
         console.error('Report generation error:', err);
         this.error = 'Failed to generate report.';
         // Update AI message to show error state
        //  this.$set(this.conversationHistory[aiMessageIndex], 'response', 'Sorry, the report could not be generated at this time.');
         this.conversationHistory[aiMessageIndex].response = 'Sorry, the report could not be generated at this time.';
        } finally {
        this.isLoading = false;
         this.scrollToBottom(); // Scroll to show result/error
      }
    },

    async rateResponse(responseId, rating) {
        console.log('FEEDBACK CLICKED: Rating response ID:', responseId, 'Rating:', rating); // **** CHECK THIS VALUE ****
        const index = this.conversationHistory.findIndex(item => item.id === responseId);
        if (index === -1) return;

         // Optimistically update UI
        //  this.$set(this.conversationHistory[index], 'feedbackGiven', true);
        this.conversationHistory[index].feedbackGiven = true; // Optimistic UI update

        try {
          console.log('Submitting feedback for response ID:', responseId, 'Rating:', rating); // **** CHECK THIS VALUE ****
            await aiService.submitFeedback(responseId, rating);
            console.log('Feedback submitted successfully for response ID:', responseId); // **** CHECK THIS VALUE ****
            // Success - UI already updated
        } catch (err) {
             console.error('Feedback submission error:', err);
             this.error = 'Failed to submit feedback.';
             // Revert UI on failure
            //  this.$set(this.conversationHistory[index], 'feedbackGiven', false);
            this.conversationHistory[index].feedbackGiven = false; // Revert optimistic update
        }
    },

    formatTimestamp(timestamp) {
      if (!timestamp) return '';
      try {
        return new Date(timestamp).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      } catch (e) { return 'Invalid Date'; }
    },

    formatMarkdown(text) {
      if (!text) return '';
      // Ensure DOMPurify runs AFTER marked
      // Add options like { breaks: true } to marked if needed
      return DOMPurify.sanitize(marked.parse(text));
    },

    scrollToBottom() {
      this.$nextTick(() => {
        const container = this.$refs.conversationContainer;
        if (container) {
          container.scrollTop = container.scrollHeight;
        }
      });
    },

    startVoiceInput() {
      if (!this.recognition) {
        this.error = 'Speech recognition is not available on this browser.';
        return;
      }
      if (this.isListening) {
        this.recognition.stop(); // Triggers onend event
      } else {
        try {
            this.recognition.start();
            this.isListening = true;
            this.error = null; // Clear previous errors
        } catch(e) {
             console.error("Speech recognition start error:", e);
             // Provide more specific error messages if possible
             if (e.name === 'not-allowed') {
                 this.error = "Microphone access was denied. Please allow access in your browser settings.";
             } else {
                this.error = `Could not start voice input: ${e.message}`;
             }
             this.isListening = false;
        }
      }
    },

    saveChat() {
        if (this.conversationHistory.length === 0) return;

        try {
            const filename = `modzee-ai-chat-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.json`;
            const chatData = {
                version: '1.0',
                persona: this.selectedPersona,
                savedAt: new Date().toISOString(),
                history: this.conversationHistory,
            };
            const jsonStr = JSON.stringify(chatData, null, 2); // Pretty print JSON
            const blob = new Blob([jsonStr], { type: 'application/json' });
            const url = URL.createObjectURL(blob);

            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();

            // Clean up
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

        } catch (err) {
            console.error("Failed to save chat:", err);
            this.error = "Could not save the chat history.";
        }
    },

    clearConversation() {
      // Professional confirmation dialog
      if (window.confirm('Are you sure you want to permanently delete this conversation history?')) {
        this.conversationHistory = [];
        localStorage.removeItem('aiConversationHistory');
        // Optionally clear persona too, or reset to default
        // localStorage.removeItem('aiSelectedPersona');
        // this.selectedPersona = 'general';
        this.error = null; // Clear any existing errors
      }
    },

    dismissError() {
      this.error = null;
    }
  }
};
</script>

<style scoped>
/* Theme Variables (adjust colors based on exact Login.jpg values) */
:root {
  --primary-color: #FF6B6B; /* Coral red from button/logo */
  --gradient-start: #FFDAB9; /* Light peachy orange */
  --gradient-end: #FFC0CB;   /* Light pinkish coral */
  --container-bg: #FFFFFF;
  --text-primary: #333333;
  --text-secondary: #666666;
  --text-on-primary: #FFFFFF;
  --input-border: #D0D0D0;
  --input-bg: #FFFFFF;
  --user-message-bg: #E6F7FF; /* Light blue - adjust if needed */
  --ai-message-bg: #F0FFF0;   /* Lighter green - adjust if needed */
  --accent-blue: #1890ff; /* Keeping blue for links/some accents if needed */
  --error-bg: #FFF1F0;
  --error-text: #D93026;
  --border-radius-main: 8px;
  --border-radius-small: 4px;
  --shadow-main: 0 4px 12px rgba(0, 0, 0, 0.08);
   --header-height: 60px;
   --footer-height: auto; /* Dynamic */
}

/* Base Container Styling */
.ai-assistant-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh; /* Full viewport height */
  padding: 20px;
  background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; /* Professional font stack */
}

.ai-assistant {
  width: 100%;
  max-width: 800px; /* Max width for the chat interface */
  height: calc(100vh - 40px); /* Full height minus padding */
  max-height: 900px; /* Max height */
  background-color: var(--container-bg);
  border-radius: var(--border-radius-main);
  box-shadow: var(--shadow-main);
  display: flex;
  flex-direction: column;
  overflow: hidden; /* Clip children */
}

/* Header Styling */
.assistant-header {
    display: flex;
    align-items: center;
    padding: 0 20px;
    height: var(--header-height);
    border-bottom: 1px solid #eee;
    flex-shrink: 0; /* Prevent shrinking */
}
.logo {
    height: 30px; /* Adjust size */
    margin-right: 15px;
}
.assistant-header h2 {
    margin: 0;
    font-size: 1.2em;
    color: var(--text-primary);
    font-weight: 600;
}
.header-controls {
    margin-left: auto; /* Push controls to the right */
}
.persona-select {
    padding: 5px 10px;
    border: 1px solid var(--input-border);
    border-radius: var(--border-radius-small);
    background-color: var(--input-bg);
    font-size: 0.9em;
    cursor: pointer;
}

/* Conversation Area Styling */
.conversation-area {
  flex-grow: 1; /* Take remaining space */
  overflow-y: auto; /* Enable scrolling */
  padding: 20px;
  display: flex;
  flex-direction: column;
}

.conversation-history {
    display: flex;
    flex-direction: column;
    gap: 15px; /* Space between messages */
}

.conversation-item {
    display: flex;
    max-width: 75%; /* Limit message width */
}
.user-message {
    align-self: flex-end;
}
.ai-message {
    align-self: flex-start;
}

.message-bubble {
    padding: 12px 18px;
    border-radius: 18px; /* More rounded */
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    word-wrap: break-word;
    position: relative; /* For meta positioning */
}
.user-message .message-bubble {
    background-color: var(--user-message-bg);
    color: var(--text-primary);
    border-bottom-right-radius: 4px; /* Slightly point */
}
.ai-message .message-bubble {
    background-color: var(--ai-message-bg);
    color: var(--text-primary);
    border-bottom-left-radius: 4px; /* Slightly point */
}

.message-sender {
    display: block;
    font-size: 0.8em;
    font-weight: 600;
    margin-bottom: 5px;
    color: var(--text-secondary);
}
.user-message .message-sender { color: var(--accent-blue); }
.ai-message .message-sender { color: #389e0d; /* Greenish */ }

.message-content {
   font-size: 0.95em;
   line-height: 1.6;
   white-space: pre-wrap; /* Respect newlines in user input */
}
/* Markdown specific styling inside AI messages */
.markdown-content > *:first-child { margin-top: 0; }
.markdown-content > *:last-child { margin-bottom: 0; }
.markdown-content p { margin-bottom: 0.8em; }
.markdown-content h1, .markdown-content h2, .markdown-content h3 { margin-top: 1em; margin-bottom: 0.5em; line-height: 1.3; }
.markdown-content ul, .markdown-content ol { padding-left: 20px; margin-bottom: 0.8em; }
.markdown-content li { margin-bottom: 0.3em; }
.markdown-content blockquote { border-left: 3px solid #ccc; padding-left: 10px; margin: 0.5em 0; color: #666; font-style: italic; }
.markdown-content pre { background-color: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; border: 1px solid #eee; font-size: 0.9em; margin: 0.5em 0; }
.markdown-content code:not(pre code) { background-color: #eee; padding: 2px 5px; border-radius: 3px; font-size: 0.9em; }


.message-meta {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin-top: 8px;
    font-size: 0.75em;
    color: var(--text-secondary);
    gap: 8px; /* Space between timestamp and feedback */
}
.timestamp {
    opacity: 0.7;
}
.feedback-buttons { display: flex; gap: 4px; }
.feedback-btn {
    background: none; border: none; cursor: pointer; padding: 2px; font-size: 1.1em; line-height: 1; opacity: 0.7; transition: opacity 0.2s;
}
.feedback-btn:hover { opacity: 1; }
.feedback-given { font-size: 1.1em; color: #52c41a; }


/* Loading Indicator */
.loading-indicator-container .message-bubble {
    background-color: var(--ai-message-bg);
    padding-top: 15px; /* Adjust padding for indicator */
    padding-bottom: 15px;
}
.typing-indicator { display: flex; gap: 4px; align-items: center; height: 16px; /* Prevent jumpiness */ }
.typing-indicator span {
    width: 7px; height: 7px; background-color: var(--primary-color); border-radius: 50%;
    animation: typing 1.2s infinite ease-in-out;
}
.typing-indicator span:nth-child(1) { animation-delay: 0s; }
.typing-indicator span:nth-child(2) { animation-delay: 0.15s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.3s; }
@keyframes typing {
    0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1.0); }
}


/* Empty State Styling */
.empty-state {
  text-align: center;
  color: var(--text-secondary);
  margin: auto; /* Center vertically and horizontally */
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px;
}
.empty-state-icon {
    font-size: 2.5em;
    margin-bottom: 15px;
    opacity: 0.8;
}
.empty-state p {
    font-size: 1.1em;
    color: var(--text-primary);
    margin-bottom: 5px;
}
.empty-state span {
    font-size: 0.9em;
}


/* Footer / Input Area Styling */
.input-area {
  padding: 15px 20px;
  border-top: 1px solid #eee;
  background-color: #f9f9f9; /* Slightly off-white background */
  flex-shrink: 0; /* Prevent shrinking */
}

.error-message {
    background-color: var(--error-bg);
    color: var(--error-text);
    padding: 8px 12px;
    border-radius: var(--border-radius-small);
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.9em;
}
.dismiss-btn {
    background: none; border: none; color: var(--error-text); font-size: 1.2em; cursor: pointer; padding: 0 5px; line-height: 1;
}

.input-wrapper {
  display: flex;
  align-items: flex-end; /* Align items to bottom for multi-line */
  background-color: var(--input-bg);
  border: 1px solid var(--input-border);
  border-radius: var(--border-radius-main);
  padding: 5px; /* Padding around textarea and buttons */
  margin-bottom: 10px; /* Space before action buttons */
  transition: border-color 0.2s ease;
}
.input-wrapper:focus-within {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(255, 107, 107, 0.2); /* Focus ring */
}

textarea {
  flex-grow: 1; /* Take available space */
  border: none;
  outline: none;
  padding: 10px; /* Internal padding */
  font-size: 1em;
  line-height: 1.5;
  resize: none; /* Disable manual resize */
  background: transparent;
  max-height: 150px; /* Limit auto-grow height */
  overflow-y: auto; /* Scroll if content exceeds max-height */
  color: var(--text-primary);
}
textarea::placeholder {
    color: #aaa;
}

.input-action-btn {
    background: none;
    border: none;
    padding: 8px;
    margin-left: 5px;
    cursor: pointer;
    color: var(--text-secondary);
    font-size: 1.3em; /* Icon size */
    line-height: 1;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s, color 0.2s;
}
.input-action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.input-action-btn:not(:disabled):hover {
    background-color: #f0f0f0;
}

.voice-btn.listening {
    color: var(--primary-color); /* Indicate active listening */
}

.submit-btn {
    background-color: var(--primary-color);
    color: var(--text-on-primary);
}
.submit-btn:not(:disabled):hover {
    background-color: darken(var(--primary-color), 10%);
    color: var(--text-on-primary); /* Ensure text stays white */
}
.submit-btn span { display: inline-block; } /* Ensure spans behave */
.submit-btn span:last-child { transform: rotate(0deg); transition: transform 0.2s ease; } /* For potential icon rotation */


/* Action Buttons Below Input */
.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap; /* Allow buttons to wrap */
    justify-content: flex-start; /* Align buttons to the left */
}
.secondary-action-btn {
    padding: 6px 12px;
    background-color: #f0f0f0;
    border: 1px solid #ddd;
    color: var(--text-secondary);
    border-radius: var(--border-radius-small);
    font-size: 0.85em;
    cursor: pointer;
    transition: background-color 0.2s, border-color 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.secondary-action-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.secondary-action-btn:not(:disabled):hover {
    background-color: #e5e5e5;
    border-color: #ccc;
}
.secondary-action-btn.clear-btn:not(:disabled):hover {
    background-color: var(--error-bg); /* Reddish hover for clear */
    border-color: var(--error-text);
    color: var(--error-text);
}

/* Scrollbar styling (optional, webkit only) */
.conversation-area::-webkit-scrollbar {
  width: 6px;
}
.conversation-area::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 3px;
}
.conversation-area::-webkit-scrollbar-thumb {
  background: #ccc;
  border-radius: 3px;
}
.conversation-area::-webkit-scrollbar-thumb:hover {
  background: #aaa;
}

</style>