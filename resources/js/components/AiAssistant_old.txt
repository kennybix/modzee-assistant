  <!-- resources/js/components/AiAssistant.vue -->
  <template>
      <div class="ai-assistant">
        <h2>modzee AI Assistant</h2>
        
        <div class="assistant-controls">
          <div class="persona-selector">
            <label for="persona">Assistant Type:</label>
            <select id="persona" v-model="selectedPersona">
              <option value="general">General Assistant</option>
              <option value="sales">Sales Analyst</option>
              <option value="hr">HR Advisor</option>
              <option value="technical">Technical Advisor</option>
            </select>
          </div>
          
          <div v-if="isAuthenticated" class="usage-info">
            <div class="usage-meter">
              <div class="usage-bar" :style="{ width: `${usagePercentage}%`, backgroundColor: usageBarColor }"></div>
            </div>
            <div class="usage-text">
              {{ usageStats.usage || 0 }} / {{ usageStats.limit || 0 }} tokens used
              ({{ usagePercentage }}%)
            </div>
          </div>
        </div>
        
        <div class="conversation-container" ref="conversationContainer">
          <div v-if="conversationHistory.length === 0" class="empty-state">
            <p>Ask me anything about team performance, engagement trends, or other business metrics!</p>
          </div>
          
          <div v-else class="conversation-history">
            <div v-for="(item, index) in conversationHistory" :key="index" class="conversation-item">
              <div class="user-prompt">
                <strong>You:</strong>
                <p>{{ item.prompt }}</p>
              </div>
              <div class="ai-response">
                <strong>AI Assistant:</strong>
                <div v-html="formatMarkdown(item.response)" class="markdown-content"></div>
                <div class="response-meta">
                  <small>{{ formatTimestamp(item.timestamp) }}</small>
                  <div class="feedback-buttons" v-if="item.id && !item.feedbackGiven">
                    <button @click="rateResponse(item.id, 'helpful')" class="feedback-btn helpful">
                      <i class="fas fa-thumbs-up"></i> Helpful
                    </button>
                    <button @click="rateResponse(item.id, 'not_helpful')" class="feedback-btn not-helpful">
                      <i class="fas fa-thumbs-down"></i> Not Helpful
                    </button>
                  </div>
                  <div v-else-if="item.feedbackGiven" class="feedback-given">
                    <i class="fas fa-check"></i> Feedback submitted
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <div v-if="isStreaming" class="streaming-indicator">
            <div class="typing-indicator">
              <span></span>
              <span></span>
              <span></span>
            </div>
            AI is typing...
          </div>
        </div>
        
        <div class="input-container">
          <div class="input-actions">
            <button @click="startVoiceInput" class="voice-btn" :disabled="isLoading || isListening">
              <i class="fas" :class="isListening ? 'fa-stop' : 'fa-microphone'"></i>
            </button>
            <textarea 
              v-model="userPrompt" 
              placeholder="Type your question here..." 
              :disabled="isLoading"
              @keyup.ctrl.enter="submitPrompt"
              ref="promptInput"
            ></textarea>
          </div>
          
          <div class="button-group">
            <button 
              @click="submitPrompt" 
              :disabled="isLoading || !userPrompt.trim()"
              class="submit-button"
            >
              <span v-if="isLoading">Processing...</span>
              <span v-else>Send</span>
            </button>
            
            <button 
              @click="generateReport" 
              :disabled="isLoading"
              class="report-button"
            >
              Generate Team Report
            </button>
            
            <button
              @click="clearConversation"
              :disabled="isLoading || conversationHistory.length === 0"
              class="clear-button"
            >
              Clear Chat
            </button>
          </div>
        </div>
        
        <div v-if="error" class="error-message">
          <p>{{ error }}</p>
          <button @click="dismissError" class="dismiss-btn">Dismiss</button>
        </div>
        
        <div v-if="showUsageStats && isAuthenticated" class="usage-stats">
          <h3>Your AI Usage</h3>
          
          <ai-chart 
            v-if="usageChartData.labels.length > 0"
            :data="usageChartData"
            type="bar"
            :options="chartOptions"
          />
          
          <button @click="showUsageStats = false" class="close-btn">Close</button>
        </div>
      </div>
    </template>
    
    <script>
    import { marked } from 'marked';
    import aiService from '../services/aiService';
    import AiChart from './AiChart.vue';
    
    export default {
      name: 'AiAssistant',
      
      components: {
        AiChart
      },
      
      data() {
        return {
          userPrompt: '',
          isLoading: false,
          isStreaming: false,
          isListening: false,
          error: null,
          selectedPersona: 'general',
          conversationHistory: [],
          showUsageStats: false,
          usageStats: {
            usage: 0,
            limit: 0,
            remaining: 0,
            percentage: 0,
            limit_exceeded: false
          },
          usageHistory: [],
          recognition: null,
          isAuthenticated: false // Will be set in mounted
        };
      },
      
      computed: {
        usagePercentage() {
          return this.usageStats.percentage || 0;
        },
        
        usageBarColor() {
          const percentage = this.usagePercentage;
          if (percentage < 50) return '#52c41a';
          if (percentage < 80) return '#faad14';
          return '#f5222d';
        },
        
        usageChartData() {
          return {
            labels: this.usageHistory.map(item => item.month),
            datasets: [{
              label: 'Token Usage',
              data: this.usageHistory.map(item => item.tokens_used),
              backgroundColor: '#1890ff'
            }]
          };
        },
        
        chartOptions() {
          return {
            plugins: {
              title: {
                display: true,
                text: 'Monthly Token Usage'
              }
            }
          };
        },
        
        previousMessages() {
          return this.conversationHistory.flatMap(item => [
            { role: 'user', content: item.prompt },
            { role: 'assistant', content: item.response }
          ]);
        }
      },
      
      watch: {
        conversationHistory: {
          handler(newValue) {
            localStorage.setItem('aiConversationHistory', JSON.stringify(newValue));
            this.$nextTick(() => {
              this.scrollToBottom();
            });
          },
          deep: true
        },
        
        selectedPersona(newValue) {
          localStorage.setItem('aiSelectedPersona', newValue);
        }
      },
      
      mounted() {
        // Check authentication status
        this.isAuthenticated = document.querySelector('meta[name="is-authenticated"]')?.content === 'true';
        
        // Load conversation history from localStorage
        const savedHistory = localStorage.getItem('aiConversationHistory');
        if (savedHistory) {
          try {
            this.conversationHistory = JSON.parse(savedHistory);
          } catch (e) {
            console.error('Error parsing saved conversation history:', e);
            localStorage.removeItem('aiConversationHistory');
          }
        }
        
        // Load selected persona from localStorage
        const savedPersona = localStorage.getItem('aiSelectedPersona');
        if (savedPersona) {
          this.selectedPersona = savedPersona;
        }
        
        // Initialize speech recognition if available
        if ('webkitSpeechRecognition' in window) {
          this.recognition = new webkitSpeechRecognition();
          this.recognition.continuous = false;
          this.recognition.interimResults = false;
          this.recognition.lang = 'en-US';
          
          this.recognition.onresult = (event) => {
            this.userPrompt = event.results[0][0].transcript;
            this.isListening = false;
          };
          
          this.recognition.onend = () => {
            this.isListening = false;
          };
          
          this.recognition.onerror = (event) => {
            this.isListening = false;
            this.error = `Speech recognition error: ${event.error}`;
          };
        }
        
        // Get usage stats if authenticated
        if (this.isAuthenticated) {
          this.fetchUsageStats();
        }
      },
      
      methods: {
        async submitPrompt() {
          if (!this.userPrompt.trim() || this.isLoading) return;
          
          this.isLoading = true;
          this.error = null;
          
          const promptText = this.userPrompt;
          this.userPrompt = '';
          
          // Add user message immediately to conversation
          const messageIndex = this.conversationHistory.length;
          this.conversationHistory.push({
            prompt: promptText,
            response: '',
            timestamp: new Date().toISOString()
          });
          
          try {
            this.isStreaming = true;
            
            // Use streaming API
            let streamingResponse = '';
            const result = await aiService.streamPrompt(promptText, {
              persona: this.selectedPersona,
              previousMessages: this.previousMessages.slice(-10), // Limit context to last 10 messages
              onChunk: (chunk) => {
                streamingResponse += chunk;
                // Update the current response in real-time
                this.$set(this.conversationHistory[messageIndex], 'response', streamingResponse);
                this.scrollToBottom();
              }
            });
            
            // Update with final response and metadata
            this.$set(this.conversationHistory[messageIndex], 'id', result.id);
            this.$set(this.conversationHistory[messageIndex], 'timestamp', result.timestamp);
            
            // Update usage stats if authenticated
            if (this.isAuthenticated) {
              this.fetchUsageStats();
            }
            
            // Optional: Read response aloud
            if (window.speechSynthesis && this.shouldSpeakResponse) {
              this.speakResponse(result.response);
            }
          } catch (error) {
            this.error = error.response?.data?.message || 'Failed to get response from AI assistant. Please try again later.';
            console.error('AI Assistant error:', error);
            
            // Remove the message if it failed
            this.conversationHistory.pop();
          } finally {
            this.isLoading = false;
            this.isStreaming = false;
            this.$nextTick(() => {
              this.scrollToBottom();
              this.$refs.promptInput.focus();
            });
          }
        },
        
        async generateReport() {
          if (this.isLoading) return;
          
          this.isLoading = true;
          this.error = null;
          
          try {
            const result = await aiService.generateReport();
            
            this.conversationHistory.push({
              id: result.id,
              prompt: "Generate a team performance report",
              response: result.response,
              timestamp: result.timestamp
            });
            
            // Update usage stats if authenticated
            if (this.isAuthenticated) {
              this.fetchUsageStats();
            }
          } catch (error) {
            this.error = 'Failed to generate report. Please try again later.';
            console.error('Report generation error:', error);
          } finally {
            this.isLoading = false;
            this.$nextTick(() => {
              this.scrollToBottom();
            });
          }
        },
        
        async rateResponse(responseId, rating) {
          try {
            await aiService.submitFeedback(responseId, rating);
            
            // Update the conversation item to show feedback was given
            const index = this.conversationHistory.findIndex(item => item.id === responseId);
            if (index !== -1) {
              this.$set(this.conversationHistory[index], 'feedbackGiven', true);
            }
          } catch (error) {
            this.error = 'Failed to submit feedback. Please try again later.';
            console.error('Feedback submission error:', error);
          }
        },
        
        async fetchUsageStats() {
          try {
            const data = await aiService.getUserUsage();
            this.usageStats = data.usage;
            this.usageHistory = data.history;
          } catch (error) {
            console.error('Error fetching usage stats:', error);
          }
        },
        
        formatTimestamp(timestamp) {
          if (!timestamp) return '';
          const date = new Date(timestamp);
          return date.toLocaleString();
        },
        
        formatMarkdown(text) {
          if (!text) return '';
          return marked(text);
        },
        
        scrollToBottom() {
          const container = this.$refs.conversationContainer;
          if (container) {
            container.scrollTop = container.scrollHeight;
          }
        },
        
        startVoiceInput() {
          if (!this.recognition) {
            this.error = 'Speech recognition is not supported in your browser.';
            return;
          }
          
          if (this.isListening) {
            this.recognition.stop();
            this.isListening = false;
          } else {
            this.recognition.start();
            this.isListening = true;
          }
        },
        
          speakResponse(text) {
        if (!window.speechSynthesis) return;
        
        // Strip markdown for better speech
        const plainText = text.replace(/[#*`_~]/g, '').replace(/\n/g, ' ');
        
        const speech = new SpeechSynthesisUtterance(plainText);
        window.speechSynthesis.speak(speech);
      },
      
      clearConversation() {
        if (window.confirm('Are you sure you want to clear the entire conversation history?')) {
          this.conversationHistory = [];
          localStorage.removeItem('aiConversationHistory');
        }
      },
      
      dismissError() {
        this.error = null;
      },
      
      toggleUsageStats() {
        this.showUsageStats = !this.showUsageStats;
        if (this.showUsageStats) {
          this.fetchUsageStats();
        }
      }
    }
  };
  </script>

  <style scoped>
  .ai-assistant {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px;
    font-family: Arial, sans-serif;
    position: relative;
  }

  h2 {
    color: #333;
    margin-bottom: 20px;
  }

  .assistant-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
  }

  .persona-selector {
    display: flex;
    align-items: center;
  }

  .persona-selector label {
    margin-right: 10px;
    font-weight: bold;
  }

  .persona-selector select {
    padding: 8px;
    border-radius: 4px;
    border: 1px solid #d9d9d9;
    background-color: white;
  }

  .usage-info {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
  }

  .usage-meter {
    width: 150px;
    height: 8px;
    background-color: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 5px;
  }

  .usage-bar {
    height: 100%;
    transition: width 0.3s ease, background-color 0.3s ease;
  }

  .usage-text {
    font-size: 12px;
    color: #666;
  }

  .conversation-container {
    height: 500px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    background-color: #f9f9f9;
    position: relative;
  }

  .empty-state {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #888;
    text-align: center;
  }

  .conversation-item {
    margin-bottom: 25px;
  }

  .user-prompt {
    margin-bottom: 10px;
  }

  .user-prompt strong {
    color: #1890ff;
  }

  .ai-response {
    background-color: #e6f7ff;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
  }

  .ai-response strong {
    color: #52c41a;
  }

  .markdown-content {
    margin-top: 10px;
    line-height: 1.6;
  }

  .markdown-content pre {
    background-color: #f0f0f0;
    padding: 10px;
    border-radius: 4px;
    overflow-x: auto;
  }

  .markdown-content code {
    background-color: #f0f0f0;
    padding: 2px 4px;
    border-radius: 4px;
  }

  .markdown-content ul, .markdown-content ol {
    padding-left: 20px;
  }

  .response-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e8e8e8;
  }

  .response-meta small {
    color: #888;
    font-size: 12px;
  }

  .feedback-buttons {
    display: flex;
    gap: 10px;
  }

  .feedback-btn {
    background: none;
    border: none;
    padding: 5px 10px;
    cursor: pointer;
    font-size: 12px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .feedback-btn.helpful {
    color: #52c41a;
  }

  .feedback-btn.helpful:hover {
    background-color: #f6ffed;
  }

  .feedback-btn.not-helpful {
    color: #f5222d;
  }

  .feedback-btn.not-helpful:hover {
    background-color: #fff1f0;
  }

  .feedback-given {
    font-size: 12px;
    color: #52c41a;
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .input-container {
    margin-bottom: 20px;
  }

  .input-actions {
    position: relative;
    margin-bottom: 10px;
  }

  .voice-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    color: #1890ff;
    cursor: pointer;
    z-index: 2;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .voice-btn:hover {
    background-color: #e6f7ff;
  }

  textarea {
    width: 100%;
    height: 100px;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 8px;
    resize: vertical;
    font-family: inherit;
    font-size: 14px;
    line-height: 1.5;
  }

  .button-group {
    display: flex;
    gap: 10px;
  }

  button {
    padding: 10px 15px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.2s ease;
  }

  button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .submit-button {
    background-color: #1890ff;
    color: white;
  }

  .submit-button:hover:not(:disabled) {
    background-color: #096dd9;
  }

  .report-button {
    background-color: #52c41a;
    color: white;
  }

  .report-button:hover:not(:disabled) {
    background-color: #389e0d;
  }

  .clear-button {
    background-color: #f5f5f5;
    color: #666;
  }

  .clear-button:hover:not(:disabled) {
    background-color: #e8e8e8;
  }

  .error-message {
    color: #f5222d;
    padding: 10px 15px;
    background-color: #fff1f0;
    border: 1px solid #ffccc7;
    border-radius: 4px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .dismiss-btn {
    background: none;
    border: none;
    color: #f5222d;
    cursor: pointer;
    padding: 5px;
    font-size: 12px;
    font-weight: normal;
  }

  .usage-stats {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(255, 255, 255, 0.95);
    z-index: 10;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  }

  .usage-stats h3 {
    margin-bottom: 20px;
    text-align: center;
  }

  .close-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    color: #666;
    cursor: pointer;
  }

  .streaming-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #1890ff;
    padding: 10px;
    font-size: 14px;
  }

  .typing-indicator {
    display: flex;
    gap: 4px;
  }

  .typing-indicator span {
    width: 8px;
    height: 8px;
    background-color: #1890ff;
    border-radius: 50%;
    animation: typing 1s infinite ease-in-out;
  }

  .typing-indicator span:nth-child(1) {
    animation-delay: 0s;
  }

  .typing-indicator span:nth-child(2) {
    animation-delay: 0.2s;
  }

  .typing-indicator span:nth-child(3) {
    animation-delay: 0.4s;
  }

  @keyframes typing {
    0%, 100% {
      transform: translateY(0);
    }
    50% {
      transform: translateY(-5px);
    }
  }
  </style>
