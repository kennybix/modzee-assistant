// resources/js/services/aiService.js
import axios from 'axios';

// Configure Axios instance for the AI API endpoints
const apiClient = axios.create({
  baseURL: '/api/ai', // Base path for AI-related routes based on PDF endpoint /api/ai/assistant
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    // Add XSRF-TOKEN handling here if using Laravel web routes / Sanctum cookies
  }
});

/**
 * Sends a prompt to the backend AI assistant endpoint as specified in the PDF.
 * @param {string} prompt - The user's prompt.
 * @returns {Promise<object>} - Promise resolving with { reply, timestamp } as per PDF spec.
 */
const getAssistantResponse = async (prompt) => {
  try {
    // Payload contains only the prompt, as specified [cite: 7]
    const payload = {
      prompt: prompt,
    };
    // Target POST /api/ai/assistant [cite: 6]
    const response = await apiClient.post('/assistant', payload);
    // Expecting { reply: '...', timestamp: '...' } based on PDF [cite: 9]
    return response.data;
  } catch (error) {
    console.error('Error fetching AI assistant response:', error);
    // Re-throw the error so the component can handle it
    throw error;
  }
};

/**
 * Requests a report generation from the backend. (Assumes /api/ai/report endpoint)
 * Adapt endpoint/payload as needed for the Bonus Reporting Task implementation.
 * @returns {Promise<object>} - Promise resolving with the report data.
 */
const generateReport = async () => {
  try {
    // Assuming the report endpoint is POST /api/ai/report
    const response = await apiClient.post('/report');
    return response.data; // Adjust expected response based on backend
  } catch (error) {
    console.error('Error generating AI report:', error);
    throw error;
  }
};

/**
 * Submits feedback for a specific AI response. (Assumes /api/ai/feedback endpoint)
 * @param {string} responseId - The ID of the AI response message (if available).
 * @param {string} rating - The feedback rating ('helpful' or 'not_helpful').
 * @param {string} [comment=''] - Optional comment.
 * @returns {Promise<object>} - Returns response data (if any) or resolves on success.
 */
const submitFeedback = async (responseId, rating, comment = '') => {
  try {
    // Assuming the feedback endpoint is POST /api/ai/feedback
    const response = await apiClient.post('/feedback', {
      response_id: responseId, // Use snake_case if Laravel expects it
      rating,
      comment
    });
    return response.data; // May return status or confirmation message
  } catch (error) {
    console.error('Error submitting feedback:', error);
    throw error;
  }
};

/**
 * Gets user AI usage statistics. (Assumes /api/ai/usage endpoint)
 * @returns {Promise<object>} - Promise resolving with usage data.
 */
const getUserUsage = async () => {
  try {
    // Assuming the usage endpoint is GET /api/ai/usage
    const response = await apiClient.get('/usage');
    return response.data; // Expecting usage stats object
  } catch (error) {
    console.error('Error getting usage stats:', error);
    throw error;
  }
};

// Export the functions for use in Vue components
export default {
  getAssistantResponse, // Use this function in AiAssistant.vue's submitPrompt method
  generateReport,
  submitFeedback,
  getUserUsage
};