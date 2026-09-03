import * as echarts from 'echarts/core';
import {BarChart, BoxplotChart, LineChart, ScatterChart} from 'echarts/charts';
import {
  AriaComponent,
  DataZoomComponent,
  DatasetComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent,
  TitleComponent,
  TooltipComponent,
  TransformComponent
} from 'echarts/components';
import {CanvasRenderer} from 'echarts/renderers';

echarts.use([
  AriaComponent,
  BarChart,
  BoxplotChart,
  CanvasRenderer,
  DataZoomComponent,
  DatasetComponent,
  GridComponent,
  LegendComponent,
  LineChart,
  MarkLineComponent,
  ScatterChart,
  TitleComponent,
  TooltipComponent,
  TransformComponent
]);

window.SSPAECharts = echarts;
