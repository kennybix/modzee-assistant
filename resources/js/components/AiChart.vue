<!-- resources/js/components/AiChart.vue -->
<template>
  <div class="ai-chart">
    <canvas ref="chartCanvas"></canvas>
  </div>
</template>

<script>
import Chart from 'chart.js/auto';

export default {
  name: 'AiChart',
  
  props: {
    type: {
      type: String,
      default: 'bar',
      validator: (value) => ['bar', 'line', 'pie', 'doughnut'].includes(value)
    },
    data: {
      type: Object,
      required: true,
      validator: (value) => {
        return value.labels && value.datasets;
      }
    },
    options: {
      type: Object,
      default: () => ({})
    }
  },
  
  data() {
    return {
      chart: null
    };
  },
  
  watch: {
    data: {
      deep: true,
      handler() {
        this.updateChart();
      }
    }
  },
  
  mounted() {
    this.createChart();
  },
  
  beforeUnmount() {
    if (this.chart) {
      this.chart.destroy();
    }
  },
  
  methods: {
    createChart() {
      const ctx = this.$refs.chartCanvas.getContext('2d');
      
      this.chart = new Chart(ctx, {
        type: this.type,
        data: this.data,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          ...this.options
        }
      });
    },
    
    updateChart() {
      if (!this.chart) {
        this.createChart();
        return;
      }
      
      this.chart.data = this.data;
      this.chart.update();
    }
  }
};
</script>

<style scoped>
.ai-chart {
  width: 100%;
  height: 300px;
  margin: 20px 0;
}
</style>
